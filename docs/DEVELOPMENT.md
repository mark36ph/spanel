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

From the repository root, run the read-only preflight first. It checks PHP/extensions, local configuration, database connectivity and migration drift without changing the system:
```bash
php bin/doctor.php
```

Then initialise the database:
```bash
mariadb -u spanel_app -p spanel < database/schema.sql
cp config/config.example.php config/config.local.php
nano config/config.local.php
php bin/migrate.php --status
php bin/migrate.php --dry-run
php bin/migrate.php
php bin/create-admin.php
```

For a quick development-only test, run:
```bash
php -S 0.0.0.0:8080 -t public
```
Use VirtualBox NAT port forwarding from host port 8080 to guest port 8080, then open `http://127.0.0.1:8080` on Windows. The PHP built-in server is not for production. Keep the VM private and do not forward this port from your router.

## Stage 1 limitations
The current build contains authentication, dashboard and an inventory UI. It does not yet create operating-system users, Nginx sites, databases for hosted sites, mailboxes, SSL certificates or cron jobs. Those operations require a dedicated, narrowly scoped privileged service, strict input validation, audit events and rollback.


## Recovering interrupted operations

The worker deliberately does not retry an operation after it may have changed server resources. First run the read-only report:

```bash
php bin/reconcile-operations.php
```

To use a different stale threshold, pass `--minutes=30` (allowed range: 5–1440). Review the reported job IDs and inspect the host, directories, database users and Nginx/PHP-FPM state before changing anything. Only after review, explicitly mark those jobs as requiring manual recovery:

```bash
php bin/reconcile-operations.php --minutes=30 --apply
```

This command never retries or deletes resources. It changes stale `running` jobs to `rollback_required` so they cannot be mistaken for completed or safe-to-retry jobs. It processes at most 500 jobs per invocation.

## Database migrations

Migrations are immutable PHP files under `database/migrations/` that return a callable receiving the PDO connection. The runner is CLI-only and uses a MariaDB advisory lock during apply operations.

- `php bin/migrate.php --status` lists applied and pending migrations.
- `php bin/migrate.php --dry-run` lists pending work without creating or modifying the migration table.
- `php bin/migrate.php` applies pending migrations in filename order.
- If an applied file's SHA-256 checksum changes, the runner stops. Add a new migration instead of editing an applied migration.

The first migration creates `website_domains` and copies legacy primary domains while keeping `websites.domain` for compatibility. It refuses invalid/colliding domains and websites with no server assignment. Because MariaDB DDL can implicitly commit, a failed migration may leave additive schema changes behind; inspect the database and follow the migration recovery notes before retrying. Do not run against live sites until tested on a disposable VM and a verified backup.
