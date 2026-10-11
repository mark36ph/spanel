# Local development setup

## Suggested VM
- VirtualBox on Windows
- Ubuntu Server 24.04 LTS
- 3–4 GB RAM, 2 CPU cores, 40 GB dynamically allocated disk
- NAT networking with port forwarding for development
- Take a VM snapshot after OS updates

## Install packages
```bash
sudo apt update
sudo apt install -y nginx mariadb-server php-fpm php-mysql php-cli php-mbstring php-xml php-curl git unzip
sudo systemctl enable --now nginx mariadb
```

## Create the database
```bash
sudo mariadb
```
Then run, replacing the example password with a long unique password:
```sql
CREATE DATABASE spanel CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'spanel_app'@'localhost' IDENTIFIED BY 'REPLACE_WITH_A_LONG_RANDOM_PASSWORD';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, INDEX ON spanel.* TO 'spanel_app'@'localhost';
FLUSH PRIVILEGES;
EXIT;
```

From the repository root:
```bash
mariadb -u spanel_app -p spanel < database/schema.sql
cp config/config.example.php config/config.local.php
nano config/config.local.php
php bin/create-admin.php
```

For a quick development-only test, run:
```bash
php -S 0.0.0.0:8080 -t public
```
Use VirtualBox NAT port forwarding from host port 8080 to guest port 8080, then open `http://127.0.0.1:8080` on Windows. The PHP built-in server is not for production. Keep the VM private and do not forward this port from your router.

## Stage 1 limitations
The current build contains authentication, dashboard and an inventory UI. It does not yet create operating-system users, Nginx sites, databases for hosted sites, mailboxes, SSL certificates or cron jobs. Those operations require a dedicated, narrowly scoped privileged service, strict input validation, audit events and rollback.
