# SPanel — Custom Hosting Control Panel

A fully custom hosting control panel for managing Projectabe, FactBurstQuiz, and future sites. The project will be developed and tested locally in an Ubuntu Server virtual machine before any production VPS migration.

## Development principles
- PHP application with MariaDB metadata storage.
- Nginx and PHP-FPM as the target hosting stack.
- Website-specific management areas for databases, cron jobs, email, SSL, files, logs, backups, and deployment.
- No paid cPanel/Plesk dependency.
- Never expose the development panel publicly.
- Privileged server operations must use a narrowly scoped, validated service; never execute arbitrary shell commands from web requests.

## Roadmap
1. Application foundation, secure administrator login, dashboard, and website inventory.
2. Website creation with validated domains, document roots, Nginx config validation, and rollback.
3. Per-website sidebar and settings.
4. Database and cron-job management.
5. File manager and Git deployment.
6. Email, SSL, backups, logs, monitoring, and security hardening.
7. Rehearse migration of Projectabe and FactBurstQuiz before production cutover.

See `docs/DEVELOPMENT.md` for the local VM setup.