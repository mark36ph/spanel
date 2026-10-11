# SPanel roadmap

This roadmap is the source of truth for the build. The initial target is one Ubuntu Server VM, but the architecture must support adding more websites and additional servers later.

## Stage 1 — Secure foundation
- [x] PHP application skeleton, administrator login, CSRF/session protections, dashboard and website inventory.
- [x] MariaDB starter schema and audit-event table.
- [x] Local Ubuntu VM development notes.
- [ ] Run the application in the Ubuntu VM and verify PHP/database behaviour.
- [ ] Hide administrator password input in the CLI setup flow and validate all setup paths.

## Stage 2 — Installation and update lifecycle
- [ ] Provide a repeatable command-line installer for a fresh Ubuntu VM or VPS.
- [ ] Installer checks supported Ubuntu/PHP versions, required privileges, disk space and dependencies before changing the system.
- [ ] Install/configure the panel, database, PHP-FPM and Nginx; create a least-privilege database user; generate local configuration; initialise/migrate the schema; create the first administrator securely.
- [ ] Make installation safe to re-run: detect existing installs, preserve local configuration/data, and stop rather than overwrite unexpectedly.
- [ ] Add an in-panel Updates page/button that checks for available SPanel releases and shows the version/change summary before applying an update.
- [ ] Run updates through a restricted root-owned updater/service, never by giving the PHP web process root or arbitrary shell access.
- [ ] Back up code/configuration/database before updates; use a controlled release/tag or approved branch, maintenance lock, schema migrations, health check, audit log and rollback path.
- [ ] Keep GitHub credentials out of the web application and repository. Prefer a read-only deploy key for the private repository or public, verifiable release artifacts.
- [ ] Document manual CLI update and recovery commands.

## Stage 3 — Multi-server and website provisioning
- [ ] Add server inventory with one local server created during installation.
- [ ] Associate every website with a hosting server; no hard-coded two-site limit.
- [ ] Keep server operations behind a narrow, authenticated, allowlisted service/API.
- [ ] Provision each website with a dedicated Linux account, PHP-FPM pool, isolated document root, Nginx configuration, validation and rollback.
- [ ] Support adding another server later without redesigning website records or the UI.

## Stage 4 — Per-website management
- [ ] Website-specific sidebar and overview.
- [ ] Domain/subdomain and DNS guidance.
- [ ] Database and database-user management.
- [ ] Cron jobs, logs, storage/resource usage and file manager.
- [ ] GitHub deployment and deployment history.

## Stage 5 — Hosting services
- [ ] SSL issuance/renewal and expiry alerts.
- [ ] Backups, retention policy and tested restores.
- [ ] Email accounts and delivery/security configuration.
- [ ] Monitoring, alerts and service health.

## Stage 6 — Production hardening and migration
- [ ] Security review, rate limiting, MFA, secure cookies/headers and dependency review.
- [ ] Test installation, upgrades, rollback, backup restore and server failure scenarios in the VM.
- [ ] Rehearse moving Projectabe and FactBurstQuiz to the VPS, verify them, and only then schedule production cutover.
- [ ] Document recovery and server replacement procedures.

## Design constraints
- Start with one server but model multiple servers from the beginning.
- A website belongs to one hosting server and owns its related resources.
- Never execute user-supplied shell commands or let PHP run as root.
- Keep panel releases separate from customer website files and configuration.
- Do not run unreviewed code from an arbitrary GitHub branch automatically on a production VPS.
- No production deployment until the VM test checklist passes.
