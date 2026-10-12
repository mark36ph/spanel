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

The first-install bootstrap targets **Ubuntu Server 24.04 LTS**. From a clean VM, clone the repository, review the installer, then run it from the repository root:

```bash
git clone https://github.com/mark36ph/spanel.git
cd spanel
less install/ubuntu-vm-install.sh
sudo bash install/ubuntu-vm-install.sh
```

It runs as root only for operating-system setup, package installation, database/service configuration and file ownership. It then creates a dedicated `spanel` system account with a non-login shell. The dedicated PHP-FPM pool and panel maintenance commands run as `spanel`, not root or the shared `www-data` account. Application code and configuration remain root-owned and read-only to `spanel`. The installer verifies this privilege separation before finishing. It creates a private panel DB credential, applies schema/migrations, registers the local VM and prompts you to create the first administrator. Before creating the protected database-secret store or installing the privileged operations helper, the installer runs every `tests/*_validation.php` regression test and `go test ./...`; any failure stops installation. It refuses to adopt an existing `spanel` account or overwrite an existing `/opt/spanel` install or SPanel Nginx site. Review the script before running it; it changes the VM and installs system packages.

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
- [Backup, restore and recovery preparation](docs/BACKUP-RESTORE-AND-RECOVERY.md)
- [Safe website removal design](docs/SAFE-WEBSITE-REMOVAL.md)
- [Restricted website/database provisioner](operations-service/PROVISIONING.md)
- [Installer and updates design](docs/INSTALLER-AND-UPDATES.md)

The repository includes an Ubuntu VM installer, local server registration, a website wizard, a queued provisioning worker, a scheduled local health collector and a server health page. Sign-in attempts are throttled using hashed email/IP identifiers. The installer now prepares isolated website and database provisioning, an encrypted root-only database credential store, an automatic queue timer, and phpMyAdmin signon using a dedicated non-root PHP-FPM pool and root-owned credential broker. The broker does not return database passwords to the panel. These changes have not yet been VM-acceptance-tested. SSL, DNS automation, automated backups/restores, safe website removal and safe in-panel updates remain incomplete. Keep the panel private and do not migrate production sites until the VM installation, signon replay/expiry, cross-site isolation and failure-recovery tests pass.
