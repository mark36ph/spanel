# SPanel Roadmap

This is the source of truth for SPanel progress. All changes go directly to `main` unless the user explicitly requests otherwise.

## Stage 0 — Design and preparation
- [x] Define project goals: custom, no paid cPanel/Plesk dependency.
- [x] Plan a multi-server architecture while starting with one Ubuntu VM.
- [x] Document the command-line installer and safe GitHub update workflow.
- [x] Define the security boundary: PHP-FPM is unprivileged; privileged operations use a narrow allowlist.
- [x] Finalise the target database model in `docs/DATABASE-DESIGN.md` before provisioning real sites (implementation and VM testing remain outstanding).
- [x] Document the website creation wizard and initial panel navigation.
- [x] Define the DNS approach: manual instructions and checks first; optional provider APIs later; custom nameservers are not required.

## Stage 1 — Local development foundation
- [x] PHP application skeleton, administrator login, CSRF/session protections, dashboard and website inventory.
- [x] Initial MariaDB schema and audit-event table.
- [x] Ubuntu VM setup notes.
- [ ] Install VirtualBox and Ubuntu Server on the Windows PC.
- [ ] Run the panel in the VM and verify PHP syntax, login, sessions, database connection and error handling.
- [x] Hide CLI administrator password input on an interactive Unix terminal; verify in the Ubuntu VM later.
- [x] Add GitHub Actions PHP syntax checks on pushes to `main` for PHP 8.2 and 8.3. [ ] Add functional/security checks and run the complete test checklist in the Ubuntu VM.
- [x] Implement the initial CLI-only, advisory-lock-protected, checksum-aware migration runner with `--status` and `--dry-run`; VM/fresh-install/legacy-upgrade/failure-recovery/restore tests remain outstanding.

## Stage 2 — Repeatable CLI installation
- [ ] Build a reviewed installer script for a clean Ubuntu VM or VPS.
- [ ] Preflight checks: supported OS, privileges, dependencies, network and disk space.
- [ ] Install and configure PHP-FPM, Nginx and MariaDB as appropriate.
- [ ] Create restricted panel account, local configuration and least-privilege DB account.
- [ ] Initialise the schema through versioned migrations.
- [ ] Create the first administrator with hidden password entry.
- [ ] Configure Nginx to expose only the public directory and validate config before reload.
- [ ] Make reinstall safe: preserve existing configuration/data and stop before destructive actions.
- [ ] Document install, repair, backup and uninstall/recovery procedures.

## Stage 3 — Core data model and server inventory
- [x] Add the first forward migration for `website_domains`, preserving `websites.domain` for compatibility and refusing invalid/normalized-colliding domains or websites without server assignments. [ ] Run and test the migration against fresh and representative legacy databases in the Ubuntu VM.
- [x] Add server inventory UI and metadata-only server registration. [ ] Have the installer register the local VM/VPS and verify it in the Ubuntu VM.
- [ ] Track server health, maintenance state, capabilities and panel/agent version.
- [x] Add server inventory, website inventory links and per-website management overview. [ ] Add health checks and inventory filters.
- [ ] Support adding more servers later without changing the website model.

## Stage 4 — Safe website provisioning
- [x] Add the initial `operation_jobs` table migration for queued/running/succeeded/failed/rollback-required/cancelled work. [x] Add an allowlisted `OperationQueue` service and CI validation tests for the website provisioning payload. [ ] Implement and test the worker and restricted typed operations.
- [ ] Build a restricted local operations service; never expose arbitrary shell execution to PHP.
- [ ] Validate domains, paths, PHP versions and generated resource names; include DNS preflight checks.
- [ ] Create a dedicated Linux user and PHP-FPM pool per website.
- [ ] Create an isolated document root and safe permissions.
- [ ] Generate Nginx config from fixed templates, test it, enable it and roll back on failure.
- [x] Add a metadata-only website registration wizard that creates a pending record and provisioning job. [ ] Implement safe provisioning, status transitions, suspend/enable and removal flows (design: `docs/WEBSITE-CREATION-AND-DNS.md`).
- [ ] Require confirmation and a backup/retention decision before destructive actions.

## Stage 5 — Per-website management
- [ ] Give each website its own overview and contextual sidebar.
- [x] List primary domains and add aliases/subdomains with type validation. [ ] Add domain editing/removal, DNS preflight and actual web-server configuration.
- [ ] Create/manage website databases and least-privilege database users during and after website creation.
- [ ] Install one phpMyAdmin instance per hosting server and add a per-database **Open phpMyAdmin** link that logs in as the correct website database user and selects that database using a short-lived, single-use secure handoff; test cross-website isolation.
- [ ] Add cron jobs with validation and audit logs.
- [ ] Add file manager with strict path containment and permission checks.
- [ ] Add logs, disk usage and resource summaries.
- [ ] Add GitHub deployment with deployment history and rollback.

## Stage 6 — Hosting services
- [ ] Issue and renew SSL certificates; show expiry and errors.
- [ ] Back up panel metadata, website files and website databases with retention settings.
- [ ] Test restores, not just backup creation.
- [ ] Add email accounts only after reviewing deliverability, spam prevention, SPF, DKIM, DMARC and abuse controls.
- [ ] Add health monitoring, service status, alerts and audit log views.

## Stage 7 — Panel updates from GitHub
- [ ] Add installed-version and available-version display.
- [ ] Publish approved releases/tags and release notes.
- [ ] Add an explicit Update button with preflight results and confirmation.
- [ ] Keep GitHub access credentials outside the web app; use a narrowly scoped read-only credential where required.
- [ ] Back up code/configuration/database and acquire an update lock.
- [ ] Stage releases separately, verify integrity, run checks and apply migrations.
- [ ] Switch releases atomically and run health checks.
- [ ] Roll back code and provide recovery steps if checks fail.
- [ ] Audit every update attempt and result.
- [ ] Test failed updates, migration recovery and restore in a disposable VM snapshot.

## Stage 8 — Security and production readiness
- [ ] Add rate limiting, security headers, secure cookies, MFA and dependency review.
- [ ] Review file permissions, service privileges, secrets handling and cross-site isolation.
- [ ] Test website isolation and the privileged service's input validation.
- [ ] Test fresh install, reinstall, upgrade, failed upgrade, backup restore and server recovery.
- [ ] Document routine maintenance and disaster recovery.

## Stage 9 — Move live websites to the VPS
- [ ] Confirm VPS requirements and supported Ubuntu version.
- [ ] Install SPanel on the VPS using the same CLI installer.
- [ ] Rehearse migration of Projectabe and FactBurstQuiz.
- [ ] Verify files, databases, domains, SSL, email and cron jobs.
- [ ] Test rollback and downtime plan before DNS changes.
- [ ] Cut over only after verification and retain recoverable backups.

## Key design rules
- No hard-coded website limit; every website belongs to a hosting server.
- Keep panel metadata separate from hosted website data.
- Each website gets its own Linux identity and PHP-FPM pool.
- Never run the web application as root or execute browser-supplied shell commands.
- Never deploy arbitrary `main` commits automatically to the production VPS; use approved releases.
- Do not mark work complete until it has been tested in the VM.
