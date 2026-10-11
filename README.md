# SPanel — Custom Hosting Control Panel

A custom hosting control panel for Projectabe, FactBurstQuiz, and future sites. Develop and test on a private Ubuntu Server virtual machine before any production VPS migration.

## Principles
- PHP, MariaDB, Nginx and PHP-FPM.
- Multi-server-ready architecture with no hard-coded website limit.
- Repeatable command-line installation on a clean Ubuntu VM or VPS.
- Planned in-panel updates from approved GitHub releases, with backups, health checks and rollback.
- Website-specific management for domains, files, databases, email, cron, SSL, backups, logs and Git deployment.
- Least-privilege website isolation and a restricted privileged operations service. PHP-FPM must never run as root or execute arbitrary commands.
- No paid cPanel/Plesk dependency.

## Preflight

After configuring `config/config.local.php`, run `php bin/doctor.php` from the repository root. It performs read-only checks for the PHP runtime, required extensions, configuration permissions, database connectivity, required tables and migration checksums. It is a preflight only; it does not install packages or configure services.

## Project docs
- [Architecture and lifecycle](docs/ARCHITECTURE.md)
- [Website creation and DNS](docs/WEBSITE-CREATION-AND-DNS.md)
- [Database design](docs/DATABASE-DESIGN.md)
- [Database migrations and phpMyAdmin](docs/DATABASE-MIGRATIONS-AND-PHPMYADMIN.md)
- [Secure one-click phpMyAdmin login](docs/PHPMYADMIN-DIRECT-LOGIN.md)
- [Roadmap](docs/ROADMAP.md)
- [Local development setup](docs/DEVELOPMENT.md)
- [Installer and updates design](docs/INSTALLER-AND-UPDATES.md)

The repository now includes metadata-only server registration, website registration/domain management, a provisioning job queue and an operations status page. These do not yet configure Linux users, Nginx, PHP-FPM, DNS or databases. The installer, privileged worker, updater and secure phpMyAdmin sign-on are still outstanding. Keep the development panel private and do not migrate production sites until VM installation, update, rollback and restore tests pass.
