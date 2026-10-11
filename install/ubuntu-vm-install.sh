#!/usr/bin/env bash
set -Eeuo pipefail
umask 027

# First-install bootstrap for a disposable/private Ubuntu Server VM.
# This installs the SPanel control panel only; hosted-site provisioning is separate.
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
[[ ! -e "$NGINX_SITE" && ! -e "$NGINX_LINK" ]] || fail "An SPanel Nginx site already exists; inspect it before proceeding."
command -v apt-get >/dev/null 2>&1 || fail "apt-get is required."
command -v systemctl >/dev/null 2>&1 || fail "systemd is required."
command -v openssl >/dev/null 2>&1 || fail "openssl is required to generate a database password."

info "Installing panel runtime packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y nginx mariadb-server php-fpm php-cli php-mysql php-mbstring php-xml php-curl php-intl git ca-certificates tar openssl

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
PHP_VERSION_ID="$(php -r 'echo PHP_VERSION_ID;')"
[[ "$PHP_VERSION_ID" -ge 80200 ]] || fail "PHP 8.2 or newer is required; detected $(php -v | head -n 1)."
[[ -S "/run/php/php${PHP_VERSION}-fpm.sock" || -e "/lib/systemd/system/php${PHP_VERSION}-fpm.service" ]] || fail "Could not locate the PHP-FPM service/socket for PHP $PHP_VERSION."

info "Creating the dedicated panel account and application directory"
if ! getent passwd "$APP_USER" >/dev/null; then
    useradd --system --home-dir "$APP_DIR" --shell /usr/sbin/nologin "$APP_USER"
fi
usermod -a -G www-data "$APP_USER"
install -d -o root -g www-data -m 0750 "$APP_DIR"
tar --exclude='./.git' --exclude='./config/config.local.php' -C "$(pwd)" -cf - . | tar -C "$APP_DIR" -xf -
chown -R root:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 0750 {} +
find "$APP_DIR" -type f -exec chmod 0640 {} +

info "Starting MariaDB and generating private panel database credentials"
systemctl enable --now mariadb
DB_PASSWORD="$(openssl rand -hex 32)"
# Password is generated as hex so it is safe in the quoted SQL literal below.
mariadb <<SQL
CREATE DATABASE IF NOT EXISTS $DB_NAME CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX, REFERENCES
  ON $DB_NAME.* TO '$DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL

info "Writing private configuration and creating the initial schema"
sed \
  -e "s/'base_url' => 'http:\/\/127.0.0.1:8080'/'base_url' => 'http:\/\/127.0.0.1:8081'/" \
  -e "s/'password' => 'CHANGE_ME'/'password' => '$DB_PASSWORD'/" \
  "$APP_DIR/config/config.example.php" > "$APP_DIR/config/config.local.php"
chown root:www-data "$APP_DIR/config/config.local.php"
chmod 0640 "$APP_DIR/config/config.local.php"
unset DB_PASSWORD

mariadb "$DB_NAME" < "$APP_DIR/database/schema.sql"
sudo -u "$APP_USER" php "$APP_DIR/bin/migrate.php"

info "Registering this VM as the local SPanel server"
HOSTNAME_VALUE="$(hostname -f 2>/dev/null || hostname)"
[[ "$HOSTNAME_VALUE" =~ ^[A-Za-z0-9.-]{1,253}$ ]] || fail "The VM hostname contains unsupported characters: $HOSTNAME_VALUE"
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
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
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
systemctl enable --now "php${PHP_VERSION}-fpm"
systemctl enable --now nginx
systemctl reload nginx

info "Creating the first administrator"
printf 'Create your SPanel administrator now. Use a unique password of at least 14 characters.\n'
sudo -u "$APP_USER" php "$APP_DIR/bin/create-admin.php"

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
The website provisioning helper, hosted-site database creation and secure phpMyAdmin
sign-on are not enabled by this installer and must be completed/tested separately.
DONE
