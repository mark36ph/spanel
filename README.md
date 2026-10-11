# SPanel — Custom Hosting Control Panel

A fully custom hosting control panel for Projectabe, FactBurstQuiz, and future sites. The project will be developed and tested locally in an Ubuntu Server virtual machine before any production VPS migration.

## Development principles
- PHP application with MariaDB metadata storage.
- Nginx and PHP-FPM as the target hosting stack.
- Multi-server-ready from the beginning: start with one local server, add hosting nodes later, and associate each website with its server.
- No hard-coded website limit and no paid cPanel/Plesk dependency.
- Command-line installation on a fresh Ubuntu VM or VPS, with safe repeatable setup.
- Planned in-panel update workflow for approved GitHub releases, with backups, validation, health checks and rollback.
- Website-specific management areas for databases, cron jobs, email, SSL, files, logs, backups, and deployment.
- Never expose the development panel publicly.
- Privileged server operations must use a narrowly scoped, validated service; never execute arbitrary shell commands from web requests.

## Roadmap
See [docs/ROADMAP.md](docs/ROADMAP.md) for stages and checklists.

## Local development
See [docs/DEVELOPMENT.md](docs/DEVELOPMENT.md) for the local VM setup.

## Installation and updates
See [docs/INSTALLER-AND-UPDATES.md](docs/INSTALLER-AND-UPDATES.md). The installer and updater are planned features and must be implemented and tested before production use.
