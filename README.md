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

## Install on a disposable Ubuntu VM

The first-install bootstrap targets **Ubuntu Server 24.04 LTS**. From the repository root on a clean VM, run:

```bash
sudo bash install/ubuntu-vm-install.sh
```

It runs as root only for operating-system setup, package installation, database/service configuration and file ownership. It then creates a dedicated `spanel` system account with a non-login shell. The dedicated PHP-FPM pool and panel maintenance commands run as `spanel`, not root or the shared `www-data` account. Application code and configuration remain root-owned and read-only to `spanel`. The installer verifies this privilege separation before finishing. It creates a private panel DB credential, applies schema/migrations, registers the local VM and prompts you to create the first administrator. It refuses to adopt an existing `spanel` account or overwrite an existing `/opt/spanel` install or SPanel Nginx site. Review the script before running it; it changes the VM and installs system packages.

For VirtualBox NAT, forward **host TCP 8081 to guest TCP 80**, then open `http://127.0.0.1:8081` on Windows. Keep the VM private; do not expose the panel publicly.

After installation, `php bin/doctor.php` is a read-only preflight for PHP/extensions, configuration permissions, database connectivity, required tables and migration checksum drift. The installer runs it at the end. It does not test hosted-site provisioning.

## Project docs
- [Architecture and lifecycle](docs/ARCHITECTURE.md)
- [Website creation and DNS](docs/WEBSITE-CREATION-AND-DNS.md)
- [Database design](docs/DATABASE-DESIGN.md)
- [Database migrations and phpMyAdmin](docs/DATABASE-MIGRATIONS-AND-PHPMYADMIN.md)
- [Secure one-click phpMyAdmin login](docs/PHPMYADMIN-DIRECT-LOGIN.md)
- [Roadmap](docs/ROADMAP.md)
- [Audit log and security events](docs/ARCHITECTURE.md#isolation-and-server-operations)
- [Local development setup](docs/DEVELOPMENT.md)
- [Ubuntu VM acceptance checklist](docs/VM-TEST-CHECKLIST.md)
- [Restricted website/database provisioner](operations-service/PROVISIONING.md)
- [Installer and updates design](docs/INSTALLER-AND-UPDATES.md)

The repository includes an Ubuntu VM installer, local server registration, a website wizard, a queued provisioning worker and an operations status page. The installer now builds and installs the restricted root-owned helper, sets up an encrypted root-only database credential store, and enables the non-root queue timer. The helper creates an isolated site user, PHP-FPM pool, Nginx site and test landing page; optional database creation uses a dedicated localhost account with grants limited to that database. These changes have not yet been built or VM-acceptance-tested. Direct phpMyAdmin sign-on, SSL, DNS automation, backups/restores and safe in-panel updates remain incomplete. Keep the panel private and do not migrate production sites until the VM installation, cross-site isolation, failure recovery and restore tests pass.
