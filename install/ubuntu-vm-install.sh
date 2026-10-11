#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

# First-install bootstrap for a disposable/private Ubuntu Server VM.
# This installs SPanel and its restricted local website/database provisioner.
readonly APP_DIR="/opt/spanel"
readonly APP_USER="spanel"
readonly DB_NAME="spanel"
readonly DB_USER="spanel_app"
readonly NGINX_SITE="/etc/nginx/sites-available/spanel"
readonly NGINX_LINK="/etc/nginx/sites-enabled/spanel"

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
info() { printf '\n==> %s\n' "$*"; }

[[ "$(id -u)" -eq 0 ]] || fail "Run with sudo: sudo bash install/ubuntu-vm-install.sh"
[[ -f /etc/os-release ]] || fail "Cannot identify the operating system."
# shellcheck disable=SC1091
source /etc/os-release
[[ "${ID:-}" == "ubuntu" && "${VERSION_ID:-}" == "24.04" ]] || fail "This first installer supports Ubuntu Server 24.04 LTS only."
[[ -f "$(pwd)/database/schema.sql" && -f "$(pwd)/bin/migrate.php" && -f "$(pwd)/config/config.example.php" ]] || fail "Run this script from the root of the SPanel repository checkout."
[[ ! -e "$APP_DIR" ]] || fail "$APP_DIR already exists. This installer refuses to overwrite an existing install or its configuration."
[[ ! -e "/etc/spanel" && ! -e "/var/lib/spanel" ]] || fail "SPanel secret-store paths already exist. Inspect them first and use a clean disposable VM."
[[ ! -e "$NGINX_SITE" && ! -e "$NGINX_LINK" ]] || fail "An SPanel Nginx site already exists; inspect it before proceeding."
command -v apt-get >/dev/null 2>&1 || fail "apt-get is required."
command -v systemctl >/dev/null 2>&1 || fail "systemd is required."
command -v openssl >/dev/null 2>&1 || fail "openssl is required to generate a database password."
command -v sudo >/dev/null 2>&1 || fail "sudo is required to run panel setup commands as the unprivileged service account."
AVAILABLE_KB="$(df -Pk / | awk 'NR == 2 {print $4}')"
[[ "$AVAILABLE_KB" =~ ^[0-9]+$ && "$AVAILABLE_KB" -ge 3000000 ]] || fail "At least 3 GB of free disk space is required."
if command -v ss >/dev/null 2>&1 && ss -ltnH '( sport = :80 )' | grep -q .; then
    fail "TCP port 80 is already in use. Stop the existing web server or use a clean VM."
fi

info "Installing panel runtime packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y nginx mariadb-server php-fpm php-cli php-mysql php-mbstring php-xml php-curl php-intl git ca-certificates tar openssl curl golang-go

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
PHP_VERSION_ID="$(php -r 'echo PHP_VERSION_ID;')"
[[ "$PHP_VERSION_ID" -ge 80200 ]] || fail "PHP 8.2 or newer is required; detected $(php -v | head -n 1)."
[[ -S "/run/php/php${PHP_VERSION}-fpm.sock" || -e "/lib/systemd/system/php${PHP_VERSION}-fpm.service" ]] || fail "Could not locate the PHP-FPM service/socket for PHP $PHP_VERSION."
readonly PHP_FPM_POOL="/etc/php/${PHP_VERSION}/fpm/pool.d/spanel.conf"
[[ ! -e "$PHP_FPM_POOL" ]] || fail "A SPanel PHP-FPM pool already exists at $PHP_FPM_POOL; inspect it before proceeding."

info "Creating the dedicated, non-root panel account and application directory"
if getent passwd "$APP_USER" >/dev/null; then
    fail "The '$APP_USER' account already exists. Inspect it first and use a clean VM; the installer will not adopt an unverified account."
fi
useradd --system --user-group --home-dir "$APP_DIR" --no-create-home --shell /usr/sbin/nologin "$APP_USER"
APP_UID="$(id -u "$APP_USER")"
APP_SHELL="$(getent passwd "$APP_USER" | cut -d: -f7)"
[[ "$APP_UID" -gt 0 && "$APP_SHELL" == "/usr/sbin/nologin" ]] || fail "The dedicated panel account is not safely configured."
usermod -a -G www-data "$APP_USER"
install -d -o root -g www-data -m 0750 "$APP_DIR"
tar --exclude='./.git' --exclude='./config/config.local.php' -C "$(pwd)" -cf - . | tar -C "$APP_DIR" -xf -
chown -R root:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 0750 {} +
find "$APP_DIR" -type f -exec chmod 0640 {} +

info "Preparing protected website database credential storage"
install -d -o root -g root -m 0750 /etc/spanel
install -d -o root -g root -m 0700 /var/lib/spanel /var/lib/spanel/secrets
openssl rand -hex 32 > /etc/spanel/database-secrets.key
chown root:root /etc/spanel/database-secrets.key
chmod 0600 /etc/spanel/database-secrets.key
[[ "$(stat -c '%U:%G:%a' /etc/spanel/database-secrets.key)" == "root:root:600" ]] || fail "Database secret key permissions are unexpected."

info "Starting MariaDB and generating private panel database credentials"
systemctl enable --now mariadb
if mariadb -Nse "SHOW DATABASES LIKE '$DB_NAME'" | grep -Fxq "$DB_NAME"; then
    fail "A database named '$DB_NAME' already exists. Inspect it and use a clean VM rather than overwriting it."
fi
if mariadb -Nse "SELECT CONCAT(User, '@', Host) FROM mysql.user WHERE User = '$DB_USER' AND Host IN ('localhost', '127.0.0.1')" | grep -q .; then
    fail "A database account for '$DB_USER' already exists. Inspect it and use a clean VM."
fi
DB_PASSWORD="$(openssl rand -hex 32)"
# Password is generated as hex so it is safe in the quoted SQL literal below.
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
CREATE USER IF NOT EXISTS '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'127.0.0.1' IDENTIFIED BY '$DB_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
  ON $DB_NAME.* TO '$DB_USER'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
  ON $DB_NAME.* TO '$DB_USER'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL

info "Writing private configuration and creating the initial schema"
sed \
  -e "s/'base_url' => 'http:\/\/127.0.0.1:8080'/'base_url' => 'http:\/\/127.0.0.1:8081'/" \
  -e "s/'password' => 'CHANGE_ME'/'password' => '$DB_PASSWORD'/" \
  "$APP_DIR/config/config.example.php" > "$APP_DIR/config/config.local.php"
chown root:www-data "$APP_DIR/config/config.local.php"
chmod 0640 "$APP_DIR/config/config.local.php"

# Fail immediately if the template changes and a generated setting was not replaced.
if ! grep -Fq "'base_url' => 'http://127.0.0.1:8081'" "$APP_DIR/config/config.local.php"; then
    fail "Could not set the expected panel base URL in config/config.local.php."
fi
if grep -Fq "'password' => 'CHANGE_ME'" "$APP_DIR/config/config.local.php"; then
    fail "The generated local configuration still contains the placeholder database password."
fi
if [[ "$(stat -c '%a' "$APP_DIR/config/config.local.php")" != "640" ]]; then
    fail "The local configuration permissions are not restricted to mode 0640."
fi
unset DB_PASSWORD

mariadb "$DB_NAME" < "$APP_DIR/database/schema.sql"
sudo -u "$APP_USER" php "$APP_DIR/bin/migrate.php"

info "Registering this VM as the local SPanel server"
HOSTNAME_VALUE="$(hostname -f 2>/dev/null || hostname | tr -cd 'A-Za-z0-9.-' | cut -c1-253)"
if [[ ! "$HOSTNAME_VALUE" =~ ^[A-Za-z0-9.-]{1,253}$ ]]; then HOSTNAME_VALUE="spanel-vm.local"; fi
mariadb "$DB_NAME" <<SQL
INSERT INTO servers (name, hostname, environment, status, is_local)
VALUES ('Local Ubuntu VM', '$HOSTNAME_VALUE', 'development', 'online', 1)
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  environment = 'development',
  status = 'online',
  is_local = 1,
  updated_at = CURRENT_TIMESTAMP;
SQL

info "Building and installing the restricted website/database provisioner"
install -d -o root -g root -m 0755 /var/www/spanel/sites
(
  cd "$APP_DIR/operations-service"
  go build -trimpath -buildvcs=false -o /tmp/spanel-ops-provisioner ./cmd/provisioner
)
install -o root -g root -m 0755 /tmp/spanel-ops-provisioner /usr/local/sbin/spanel-ops
rm -f /tmp/spanel-ops-provisioner
install -o root -g root -m 0440 "$APP_DIR/install/sudoers/spanel-ops" /etc/sudoers.d/spanel-ops
if ! visudo -cf /etc/sudoers.d/spanel-ops; then
    rm -f /etc/sudoers.d/spanel-ops /usr/local/sbin/spanel-ops
    fail "Restricted operations sudoers configuration failed validation."
fi
[[ "$(stat -c '%U:%G:%a' /usr/local/sbin/spanel-ops)" == "root:root:755" ]] || fail "Restricted helper ownership or permissions are unexpected."
[[ "$(stat -c '%U:%G:%a' /var/lib/spanel/secrets)" == "root:root:700" ]] || fail "Database secret directory permissions are unexpected."
printf 'Restricted helper installed; database credentials will be encrypted in a root-only store.\n'

info "Configuring a dedicated PHP-FPM pool for the non-root SPanel account"
cat > "$PHP_FPM_POOL" <<POOL
[spanel]
user = spanel
group = www-data
listen = /run/php/spanel.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
pm.max_requests = 500
clear_env = yes
security.limit_extensions = .php
POOL
chown root:root "$PHP_FPM_POOL"
chmod 0644 "$PHP_FPM_POOL"
if ! "/usr/sbin/php-fpm${PHP_VERSION}" -t; then
    rm -f "$PHP_FPM_POOL"
    fail "PHP-FPM configuration validation failed; the new SPanel pool was removed."
fi
systemctl enable --now "php${PHP_VERSION}-fpm"
systemctl reload "php${PHP_VERSION}-fpm"

info "Configuring Nginx to serve only SPanel's public directory"
cat > "$NGINX_SITE" <<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name 127.0.0.1 localhost _;

    root /opt/spanel/public;
    index index.php;
    server_tokens off;

    access_log /var/log/nginx/spanel_access.log;
    error_log /var/log/nginx/spanel_error.log warn;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        try_files \$uri =404;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/spanel.sock;
    }

    location ~ /\. {
        deny all;
    }
}
NGINX
ln -s "$NGINX_SITE" "$NGINX_LINK"
if ! nginx -t; then
    rm -f "$NGINX_LINK" "$NGINX_SITE"
    fail "Nginx configuration validation failed; the SPanel site config was removed."
fi
systemctl enable --now nginx
systemctl reload nginx

info "Creating the first administrator"
printf 'Create your SPanel administrator now. Use a unique password of at least 14 characters.\n'
sudo -u "$APP_USER" php "$APP_DIR/bin/create-admin.php"

info "Checking the local HTTP/PHP-FPM path"
if ! curl --fail --silent --show-error http://127.0.0.1/login.php -o /dev/null; then
    fail "SPanel did not return HTTP success from /login.php. Inspect nginx and PHP-FPM logs before retrying."
fi
printf 'HTTP login page returned successfully.\n'

# The document root must never expose application configuration or source data.
for protected_path in /config/config.local.php /config/config.example.php /database/schema.sql /bin/doctor.php; do
    http_code="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' "http://127.0.0.1${protected_path}")" \
        || fail "Could not check protected path ${protected_path}."
    if [[ "$http_code" == "200" ]]; then
        fail "Protected path ${protected_path} is publicly accessible (HTTP 200)."
    fi
    printf 'Protected path %s is not publicly served (HTTP %s).\n' "$protected_path" "$http_code"
done

info "Verifying privilege separation and application permissions"
[[ "$(id -u "$APP_USER")" -gt 0 ]] || fail "The panel account must never be root."
[[ "$(getent passwd "$APP_USER" | cut -d: -f7)" == "/usr/sbin/nologin" ]] || fail "The panel account must not have an interactive login shell."
[[ "$(stat -c '%U:%G:%a' "$APP_DIR")" == "root:www-data:750" ]] || fail "The application directory ownership or permissions are unexpected."
[[ "$(stat -c '%U:%G:%a' "$APP_DIR/config/config.local.php")" == "root:www-data:640" ]] || fail "The private configuration ownership or permissions are unexpected."
if sudo -u "$APP_USER" test -w "$APP_DIR/bin/worker.php"; then
    fail "The panel account must not be able to modify its worker code."
fi
printf 'SPanel runs under non-root account %s (UID %s); application code remains root-owned.\n' "$APP_USER" "$(id -u "$APP_USER")"

info "Running the read-only preflight"
sudo -u "$APP_USER" php "$APP_DIR/bin/doctor.php"

cat <<'DONE'

SPanel panel installation finished.

Next:
  1. In VirtualBox, configure NAT port forwarding: host port 8081 -> guest port 80 (TCP).
  2. Open http://127.0.0.1:8081 on the Windows host.
  3. Sign in with the administrator you just created.
  4. Review Servers, Websites and Operations.

This is a private development install. Do not expose it to the public internet.
The installer runs as root for system setup; panel commands run as the dedicated
non-root 'spanel' account. Application code stays root-owned and read-only to that
account. The fixed root-owned helper can provision isolated websites and optional
MariaDB databases. Direct phpMyAdmin sign-on, DNS automation and SSL remain disabled.
DONE
