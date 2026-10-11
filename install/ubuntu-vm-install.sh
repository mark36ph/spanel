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
[[ ! -e "/usr/local/sbin/spanel-ops" && ! -e "/usr/local/sbin/spanel-pma-credential-broker" && ! -e "/etc/sudoers.d/spanel-ops" ]] || fail "A SPanel operations helper or sudoers rule already exists; inspect it before proceeding."
[[ ! -e "/etc/phpmyadmin/conf.d/99-spanel.php" ]] || fail "An existing SPanel phpMyAdmin configuration was found; inspect it before proceeding."
[[ ! -e "/etc/systemd/system/spanel-worker.service" && ! -e "/etc/systemd/system/spanel-worker.timer" ]] || fail "A SPanel worker unit already exists; inspect it before proceeding."
command -v apt-get >/dev/null 2>&1 || fail "apt-get is required."
command -v systemctl >/dev/null 2>&1 || fail "systemd is required."
command -v openssl >/dev/null 2>&1 || fail "openssl is required to generate a database password."
command -v sudo >/dev/null 2>&1 || fail "sudo is required to run panel setup commands as the unprivileged service account."
getent passwd spanel-pma >/dev/null && fail "The spanel-pma account already exists. Use a clean VM rather than adopting an unverified account."
getent group spanel-pma >/dev/null && fail "The spanel-pma group already exists. Use a clean VM rather than adopting an unverified group."
AVAILABLE_KB="$(df -Pk / | awk 'NR == 2 {print $4}')"
[[ "$AVAILABLE_KB" =~ ^[0-9]+$ && "$AVAILABLE_KB" -ge 3000000 ]] || fail "At least 3 GB of free disk space is required."
if command -v ss >/dev/null 2>&1 && ss -ltnH '( sport = :80 )' | grep -q .; then
    fail "TCP port 80 is already in use. Stop the existing web server or use a clean VM."
fi

info "Installing panel runtime packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y nginx mariadb-server php-fpm php-cli php-mysql php-mbstring php-xml php-curl php-intl git ca-certificates tar openssl curl golang-go debconf-utils
printf 'phpmyadmin phpmyadmin/dbconfig-install boolean false\nphpmyadmin phpmyadmin/reconfigure-webserver multiselect \n' | debconf-set-selections
DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends phpmyadmin

PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
PHP_VERSION_ID="$(php -r 'echo PHP_VERSION_ID;')"
[[ "$PHP_VERSION_ID" -ge 80200 ]] || fail "PHP 8.2 or newer is required; detected $(php -v | head -n 1)."
php -r 'exit(function_exists("posix_geteuid") && extension_loaded("pdo_mysql") && extension_loaded("openssl") ? 0 : 1);' \
    || fail "PHP CLI must provide POSIX, PDO MySQL and OpenSSL for the restricted credential broker."
[[ -S "/run/php/php${PHP_VERSION}-fpm.sock" || -e "/lib/systemd/system/php${PHP_VERSION}-fpm.service" ]] || fail "Could not locate the PHP-FPM service/socket for PHP $PHP_VERSION."
readonly PHP_FPM_POOL="/etc/php/${PHP_VERSION}/fpm/pool.d/spanel.conf"
readonly PHP_PMA_FPM_POOL="/etc/php/${PHP_VERSION}/fpm/pool.d/spanel-pma.conf"
[[ ! -e "$PHP_FPM_POOL" ]] || fail "A SPanel PHP-FPM pool already exists at $PHP_FPM_POOL; inspect it before proceeding."
[[ ! -e "$PHP_PMA_FPM_POOL" ]] || fail "A SPanel phpMyAdmin PHP-FPM pool already exists at $PHP_PMA_FPM_POOL; inspect it before proceeding."
[[ -f /etc/phpmyadmin/config.inc.php && -f /usr/share/phpmyadmin/index.php ]] || fail "The phpMyAdmin package did not install its expected files."

info "Creating the dedicated, non-root panel account and application directory"
if getent passwd "$APP_USER" >/dev/null; then
    fail "The '$APP_USER' account already exists. Inspect it first and use a clean VM; the installer will not adopt an unverified account."
fi
useradd --system --user-group --home-dir "$APP_DIR" --no-create-home --shell /usr/sbin/nologin "$APP_USER"
useradd --system --user-group --home-dir /var/lib/spanel/phpmyadmin --no-create-home --shell /usr/sbin/nologin spanel-pma
PMA_UID="$(id -u spanel-pma)"
[[ "$PMA_UID" -gt 0 ]] || fail "The phpMyAdmin PHP-FPM account must not be root."

# phpMyAdmin's config (including its generated blowfish secret, if present)
# must be readable by the isolated phpMyAdmin pool, never by the panel account.
chown -R root:spanel-pma /etc/phpmyadmin
find /etc/phpmyadmin -type d -exec chmod 0755 {} +
find /etc/phpmyadmin -type f -exec chmod 0640 {} +
if [[ -d /var/lib/phpmyadmin ]]; then
    chown root:spanel-pma /var/lib/phpmyadmin
    chmod 0750 /var/lib/phpmyadmin
    find /var/lib/phpmyadmin -maxdepth 1 -type f -exec chown root:spanel-pma {} + -exec chmod 0640 {} +
    find /var/lib/phpmyadmin -mindepth 1 -maxdepth 1 -type d -exec chown spanel-pma:spanel-pma {} + -exec chmod 0700 {} +
fi
APP_UID="$(id -u "$APP_USER")"
APP_SHELL="$(getent passwd "$APP_USER" | cut -d: -f7)"
[[ "$APP_UID" -gt 0 && "$APP_SHELL" == "/usr/sbin/nologin" ]] || fail "The dedicated panel account is not safely configured."
usermod -a -G www-data "$APP_USER"
install -d -o root -g www-data -m 0750 "$APP_DIR"
tar --exclude='./.git' --exclude='./config/config.local.php' -C "$(pwd)" -cf - . | tar -C "$APP_DIR" -xf -
chown -R root:www-data "$APP_DIR"
find "$APP_DIR" -type d -exec chmod 0750 {} +
find "$APP_DIR" -type f -exec chmod 0640 {} +

info "Checking PHP syntax for the website and signon entry points"
for php_file in \
    app/Security.php \
    app/Auth.php \
    app/LoginRateLimiter.php \
    app/PhpMyAdminHandoff.php \
    public/site.php \
    public/health.php \
    public/login.php \
    public/logout.php \
    public/phpmyadmin-launch.php \
    public/phpmyadmin-logout.php \
    public/phpmyadmin-auth-check.php \
    public/create-site.php \
    bin/worker.php \
    bin/doctor.php \
    bin/health-check.php \
    install/helpers/spanel-pma-credential-broker.php; do
    php -l "$APP_DIR/$php_file" >/dev/null || fail "PHP syntax validation failed for $php_file."
done

info "Preparing protected website database credential storage"
install -d -o root -g root -m 0750 /etc/spanel
install -d -o root -g root -m 0711 /var/lib/spanel
install -d -o "$APP_USER" -g "$APP_USER" -m 0700 /var/lib/spanel/panel-sessions
install -d -o root -g root -m 0700 /var/lib/spanel/secrets
install -d -o root -g spanel-pma -m 0770 /var/lib/spanel/phpmyadmin-sessions
(umask 077; openssl rand -hex 32 > /etc/spanel/database-secrets.key)
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
install -o root -g root -m 0755 "$APP_DIR/install/helpers/spanel-pma-credential-broker.php" /usr/local/sbin/spanel-pma-credential-broker
[[ "$(stat -c '%U:%G:%a' /usr/local/sbin/spanel-pma-credential-broker)" == "root:root:755" ]] || fail "phpMyAdmin credential broker ownership or permissions are unexpected."
install -o root -g root -m 0440 "$APP_DIR/install/sudoers/spanel-ops" /etc/sudoers.d/spanel-ops
if ! visudo -cf /etc/sudoers.d/spanel-ops; then
    rm -f /etc/sudoers.d/spanel-ops /usr/local/sbin/spanel-ops /usr/local/sbin/spanel-pma-credential-broker
    fail "Restricted operations sudoers configuration failed validation."
fi
[[ "$(stat -c '%U:%G:%a' /usr/local/sbin/spanel-ops)" == "root:root:755" ]] || fail "Restricted helper ownership or permissions are unexpected."
[[ "$(stat -c '%U:%G:%a' /usr/local/sbin/spanel-pma-credential-broker)" == "root:root:755" ]] || fail "phpMyAdmin credential broker ownership or permissions are unexpected."
[[ "$(stat -c '%U:%G:%a' /var/lib/spanel/secrets)" == "root:root:700" ]] || fail "Database secret directory permissions are unexpected."
install -o root -g root -m 0644 "$APP_DIR/install/systemd/spanel-worker.service" /etc/systemd/system/spanel-worker.service
install -o root -g root -m 0644 "$APP_DIR/install/systemd/spanel-worker.timer" /etc/systemd/system/spanel-worker.timer
install -o root -g root -m 0644 "$APP_DIR/install/systemd/spanel-health-check.service" /etc/systemd/system/spanel-health-check.service
install -o root -g root -m 0644 "$APP_DIR/install/systemd/spanel-health-check.timer" /etc/systemd/system/spanel-health-check.timer
systemctl daemon-reload
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
php_admin_value[session.save_path] = /var/lib/spanel/panel-sessions
POOL
chown root:root "$PHP_FPM_POOL"
chmod 0644 "$PHP_FPM_POOL"
if ! "/usr/sbin/php-fpm${PHP_VERSION}" -t; then
    rm -f "$PHP_FPM_POOL"
    fail "PHP-FPM configuration validation failed; the new SPanel pool was removed."
fi
systemctl enable --now "php${PHP_VERSION}-fpm"
systemctl reload "php${PHP_VERSION}-fpm"

info "Configuring isolated phpMyAdmin PHP-FPM pool and signon configuration"
cat > "$PHP_PMA_FPM_POOL" <<POOL
[spanel-pma]
user = spanel-pma
group = spanel-pma
listen = /run/php/spanel-pma.sock
listen.owner = www-data
listen.group = spanel-pma
listen.mode = 0660
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
pm.max_requests = 300
clear_env = yes
security.limit_extensions = .php
php_admin_value[session.save_path] = /var/lib/spanel/phpmyadmin-sessions
php_admin_value[session.gc_maxlifetime] = 1800
php_admin_value[session.cookie_httponly] = 1
php_admin_value[session.cookie_path] = /phpmyadmin/
php_admin_value[session.cookie_samesite] = Strict
php_admin_value[session.use_strict_mode] = 1
POOL
chown root:root "$PHP_PMA_FPM_POOL"
chmod 0644 "$PHP_PMA_FPM_POOL"

[[ -f /etc/phpmyadmin/config.inc.php ]] || fail "phpMyAdmin configuration file is missing."
grep -Fq '/etc/phpmyadmin/conf.d' /etc/phpmyadmin/config.inc.php || fail "The installed phpMyAdmin configuration does not load /etc/phpmyadmin/conf.d; refusing to continue with an unconfigured login."
install -d -o root -g root -m 0755 /etc/phpmyadmin/conf.d
PANEL_BASE_URL="$(php -r '$c = require "/opt/spanel/config/config.local.php"; echo $c["app"]["base_url"] ?? "";')"
[[ "$PANEL_BASE_URL" =~ ^https?://[A-Za-z0-9.-]+(:[0-9]{1,5})?$ ]] || fail "The panel base URL is invalid for phpMyAdmin signon configuration."
sed "s|__SPANEl_BASE_URL__|$PANEL_BASE_URL|g" > /etc/phpmyadmin/conf.d/99-spanel.php <<'PMA_CONFIG'
<?php
declare(strict_types=1);
$spanelBaseUrl = '__SPANEl_BASE_URL__';
$spanelSecureCookie = str_starts_with($spanelBaseUrl, 'https://');
$cfg['AllowArbitraryServer'] = false;
$cfg['SessionSavePath'] = '/var/lib/spanel/phpmyadmin-sessions';
$cfg['CookieSameSite'] = 'Strict';
$cfg['CookieSecure'] = $spanelSecureCookie;
$cfg['Servers'][1]['auth_type'] = 'signon';
$cfg['Servers'][1]['host'] = 'localhost';
$cfg['Servers'][1]['port'] = '3306';
$cfg['Servers'][1]['AllowNoPassword'] = false;
$cfg['Servers'][1]['SignonSession'] = 'spanel_pma_signon';
$cfg['Servers'][1]['SignonCookieParams'] = [
    'lifetime' => 60,
    'path' => '/phpmyadmin/',
    'secure' => $spanelSecureCookie,
    'httponly' => true,
    'samesite' => 'Strict',
];
$cfg['Servers'][1]['SignonURL'] = $spanelBaseUrl . '/login.php';
$cfg['Servers'][1]['LogoutURL'] = $spanelBaseUrl . '/phpmyadmin-logout.php';
$cfg['Servers'][1]['hide_connection_errors'] = true;
PMA_CONFIG
chown root:spanel-pma /etc/phpmyadmin/conf.d/99-spanel.php
chmod 0640 /etc/phpmyadmin/conf.d/99-spanel.php

if ! "/usr/sbin/php-fpm${PHP_VERSION}" -t; then
    rm -f "$PHP_FPM_POOL" "$PHP_PMA_FPM_POOL"
    fail "PHP-FPM configuration validation failed; the new SPanel/phpMyAdmin pools were removed."
fi
systemctl reload "php${PHP_VERSION}-fpm"

info "Configuring Nginx to serve SPanel and phpMyAdmin"
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

    location = /phpmyadmin {
        return 302 /phpmyadmin/;
    }

    location = /phpmyadmin-auth-check.php {
        internal;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME /opt/spanel/public/phpmyadmin-auth-check.php;
        fastcgi_pass unix:/run/php/spanel.sock;
    }

    location ~ /\. {
        deny all;
    }

    location ~ ^/phpmyadmin/(setup|libraries|templates|vendor|sql|doc|examples)/ {
        deny all;
    }

    location ~ ^/phpmyadmin/(.+\.php)$ {
        auth_request /phpmyadmin-auth-check.php;
        error_page 401 = @spanel_pma_login;
        add_header Cache-Control "no-store, private" always;
        add_header Referrer-Policy "no-referrer" always;
        root /usr/share;
        try_files \$uri =404;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/spanel-pma.sock;
    }

    location ~ \.php$ {
        try_files \$uri =404;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/spanel.sock;
    }

    location /phpmyadmin/ {
        auth_request /phpmyadmin-auth-check.php;
        error_page 401 = @spanel_pma_login;
        add_header Cache-Control "no-store, private" always;
        add_header Referrer-Policy "no-referrer" always;
        root /usr/share;
        index index.php;
        try_files \$uri \$uri/ =404;
    }

    location @spanel_pma_login {
        add_header Cache-Control "no-store, private" always;
        add_header Referrer-Policy "no-referrer" always;
        return 302 /login.php;
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
PMA_HTTP_STATUS="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' http://127.0.0.1/phpmyadmin/)" \
    || fail "Could not check the phpMyAdmin signon endpoint."
[[ "$PMA_HTTP_STATUS" == "302" ]] || fail "phpMyAdmin did not redirect unauthenticated access to SPanel sign-in (HTTP $PMA_HTTP_STATUS)."
AUTH_CHECK_HTTP_STATUS="$(curl --silent --show-error --output /dev/null --write-out '%{http_code}' http://127.0.0.1/phpmyadmin-auth-check.php)" \
    || fail "Could not verify the internal phpMyAdmin authorization endpoint."
[[ "$AUTH_CHECK_HTTP_STATUS" == "404" ]] || fail "The internal phpMyAdmin authorization endpoint is externally reachable (HTTP $AUTH_CHECK_HTTP_STATUS)."
printf 'phpMyAdmin signon endpoint and internal authorization route passed their HTTP checks.\n'

info "Enabling the queued provisioning worker"
systemctl enable --now spanel-worker.timer
systemctl is-active --quiet spanel-worker.timer || fail "The SPanel provisioning timer did not start."
systemctl enable --now spanel-health-check.timer
systemctl is-active --quiet spanel-health-check.timer || fail "The SPanel health-check timer did not start."
sudo -u "$APP_USER" php "$APP_DIR/bin/health-check.php" || fail "The initial local health check failed; inspect the systemd journal and panel database before proceeding."

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
[[ "$(id -u spanel-pma)" -gt 0 ]] || fail "phpMyAdmin must run as a non-root account."
[[ "$(getent passwd spanel-pma | cut -d: -f7)" == "/usr/sbin/nologin" ]] || fail "The phpMyAdmin account must not have an interactive login shell."
if sudo -u "$APP_USER" test -r /etc/phpmyadmin/conf.d/99-spanel.php; then
    fail "The SPanel account must not be able to read phpMyAdmin's private configuration."
fi
if sudo -u "$APP_USER" test -r /var/lib/spanel/phpmyadmin-sessions; then
    fail "The SPanel account must not be able to read phpMyAdmin signon sessions."
fi
if sudo -u spanel-pma test -r /var/lib/spanel/panel-sessions; then
    fail "The phpMyAdmin account must not be able to read SPanel sessions."
fi
sudo -u spanel-pma test -r /etc/phpmyadmin/conf.d/99-spanel.php || fail "The isolated phpMyAdmin account cannot read its configuration."
if sudo -u spanel-pma test -r "$APP_DIR/config/config.local.php"; then
    fail "The phpMyAdmin account must not be able to read SPanel database credentials."
fi
sudo -u spanel-pma test -r /var/lib/spanel/phpmyadmin-sessions || fail "The isolated phpMyAdmin account cannot access its session store."
if sudo -u "$APP_USER" test -w /run/php/spanel-pma.sock; then
    fail "The SPanel account must not be able to connect directly to the phpMyAdmin PHP-FPM pool."
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
account. The fixed root-owned helpers provision isolated websites/databases and perform
a short-lived phpMyAdmin signon without returning database passwords to the panel.
This integration is code-complete but has not passed VM acceptance testing. Keep
the VM private; public use requires HTTPS and the remaining DNS/SSL work.
DONE
