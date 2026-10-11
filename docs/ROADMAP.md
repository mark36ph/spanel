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
- [x] Ubuntu VM setup notes and a step-by-step [VM acceptance checklist](VM-TEST-CHECKLIST.md).
- [ ] Install VirtualBox and Ubuntu Server on the Windows PC.
- [ ] Run the panel in the VM and verify PHP syntax, login, sessions, database connection and error handling.
- [x] Hide CLI administrator password input on an interactive Unix terminal; verify in the Ubuntu VM later.
- [x] Add GitHub Actions PHP syntax checks on pushes to `main` for PHP 8.2 and 8.3. [ ] Add functional/security checks and run the complete test checklist in the Ubuntu VM.
- [x] Implement the initial CLI-only, advisory-lock-protected, checksum-aware migration runner with `--status` and `--dry-run`; VM/fresh-install/legacy-upgrade/failure-recovery/restore tests remain outstanding.

## Stage 2 — Repeatable CLI installation
- [x] Add a first-install Ubuntu Server 24.04 VM installer draft at `install/ubuntu-vm-install.sh`. [ ] Review and run it on a clean disposable VM; verify fresh install, permissions, Nginx/PHP-FPM, migrations and login.
- [x] Add a read-only CLI preflight for PHP/extensions, local configuration, DB connectivity, required tables and migration checksum drift. [ ] Verify its output on the Ubuntu VM.
- [x] Add installer preflight checks for Ubuntu 24.04, root privileges, dependencies, disk space and port 80. [ ] Verify failure paths on the VM.
- [x] Add installer steps to install and configure PHP-FPM, Nginx and MariaDB. [ ] Verify service startup and HTTP/PHP-FPM routing in the VM.
- [x] Add installer steps for an unprivileged panel account, private local configuration and scoped database grants. [ ] Review effective permissions in the VM.
- [x] Add installer steps to import the base schema and apply versioned migrations. [ ] Verify fresh-install migration results in the VM.
- [x] Add installer step to create the first administrator with hidden password entry. [ ] Verify interactive password input in the VM.
- [x] Add installer Nginx configuration limited to the public directory and validate it before reload. [ ] Verify routing and denied paths in the VM.
- [x] Verify the generated base URL, reject an unreplaced DB-password placeholder, and check protected config/source paths are not served as HTTP 200. [ ] Exercise these guards in the Ubuntu VM.
- [x] Refuse to overwrite an existing `/opt/spanel` install or existing SPanel Nginx site. [ ] Add and test a documented repair/resume path for interrupted first installs.
- [x] Document first installation and basic interrupted-operation recovery. [ ] Document and test install repair, backup, restore and uninstall procedures.

## Stage 3 — Core data model and server inventory
- [x] Add the first forward migration for `website_domains`, preserving `websites.domain` for compatibility and refusing invalid/normalized-colliding domains or websites without server assignments. [ ] Run and test the migration against fresh and representative legacy databases in the Ubuntu VM.
- [x] Add server inventory UI and metadata-only server registration, and add local-server registration to the installer draft. [ ] Verify registration in the Ubuntu VM.
- [x] Add local read-only service health snapshots and a scheduled collector for Nginx, MariaDB, PHP-FPM, the worker timer and disk space; VM verification remains outstanding. [ ] Track remote server health, maintenance state, capabilities and panel/agent version.
- [x] Add server inventory, website inventory links and per-website management overview. [x] Add website inventory search/status filters and latest-operation visibility. [x] Add server inventory search/status/environment filters. [ ] Verify local health checks in the Ubuntu VM.
- [ ] Support adding more servers later without changing the website model.

## Stage 4 — Safe website provisioning
- [x] Add the initial `operation_jobs` table migration for queued/running/succeeded/failed/rollback-required/cancelled work.
- [x] Add an index for bounded scans of stale running jobs. [ ] Apply and inspect the migration on a disposable MariaDB VM.
- [x] Add an allowlisted `OperationQueue` service and CI validation tests for the website provisioning payload.
- [x] Add a CLI-only, advisory-lock-protected worker that checks job ownership, bounds helper output/time and records database metadata only after a matching helper response. Uncertain post-invocation failures are marked `rollback_required`. [x] Add status filters and recovery-required visibility to the operations page. [ ] Run worker tests in the Ubuntu VM.
- [x] Add an exact typed request/response contract and automated tests for extra fields, type mismatches, domain mismatches, PHP version and database-result mismatches.
- [x] Add read-only-first stale-operation reconciliation; explicit apply marks interrupted jobs `rollback_required` and never retries them automatically. [ ] Test concurrent worker/recovery behavior in the Ubuntu VM.
- [x] Implement the root-owned restricted Go helper for website provisioning with optional database creation: fixed command arguments, validated domain/ID/PHP version, root-owned directory checks, resource collision refusal, PHP-FPM/Nginx config validation, a test landing page, local HTTP health check and best-effort rollback. [ ] Review and test all success/failure paths in the Ubuntu VM.
- [x] Add a bounded JSON-line protocol codec and fail-closed PHP client using a fixed local Unix socket with timeouts and response-size limits. [x] Add a systemd socket-activated Go service that runs unprivileged and implements read-only `website.preflight` facts. [x] Keep write operations in a separate typed root-owned helper; install it and its narrow sudoers rule from the VM installer. [ ] VM-test the privilege boundary and isolation.
- [x] Harden the Go provisioner and read-only service against duplicate JSON keys, incorrect scalar types, trailing JSON values and single-label domains; fail closed on unexpected site-user lookup errors and unresolved UIDs. [ ] Add end-to-end malformed-frame and resource-isolation tests in the Ubuntu VM.
- [ ] Add authoritative DNS resolution checks to preflight; DNS matching remains explicitly unknown until implemented and VM-verified.
- [x] Add a deterministic, non-privileged provisioning plan for website Linux usernames, document roots, PHP-FPM pools/sockets, Nginx templates and database identifiers, with CI validation tests including hidden-file deny-rule ordering (except `.well-known`). [ ] Integrate it into the restricted helper and verify generated configs on Ubuntu.
- [x] Add a data-only provisioning preflight evaluator with strict typed facts, PHP/FPM compatibility, Nginx test status, disk-space and resource-conflict checks, DNS warnings, and optional database checks. [x] Connect the data-only preflight evaluator to a read-only fact collector and CLI. [ ] Verify the collector and CLI in the Ubuntu VM; Nginx validity, DNS matching, and MariaDB authentication/privileges remain unknown/unverified.
- [x] Define a strict `website.preflight` operations request/response contract and a fixed, data-only provisioning workflow with ordered steps and rollback labels. [ ] Connect to the restricted service and test every step in the Ubuntu VM.
- [x] Implement dedicated Linux user, document root, PHP-FPM pool, Nginx site, test landing page and local HTTP health check in the restricted helper. [ ] Validate isolation and rollback in the Ubuntu VM.
- [x] Create an isolated document root with site ownership and Nginx-readable group permissions in the restricted helper. [ ] Validate permissions and cross-site isolation in the Ubuntu VM.
- [x] Generate Nginx config from a fixed template, test before/after enabling, and attempt rollback on failure. [ ] Validate Nginx behavior and rollback in the Ubuntu VM.
- [x] Connect the website creation wizard to queued provisioning, optional database creation, success/failure metadata and a 30-second systemd queue timer. [ ] VM-test the end-to-end flow; suspend/enable and safe removal remain planned (design: `docs/WEBSITE-CREATION-AND-DNS.md`).
- [ ] Implement the removal workflow with typed confirmation, backup/retention choice, dependency checks, audit trail, dry-run, restricted-helper validation and tested recovery. Design: `docs/SAFE-WEBSITE-REMOVAL.md`.

## Stage 5 — Per-website management
- [x] Give each website a management overview with recent operation history and contextual links. [ ] Add a dedicated contextual sidebar as website modules are implemented.
- [x] List primary domains, add aliases/subdomains with type validation, and remove pending non-primary domain records with audit logging. [ ] Add domain editing, DNS preflight and actual web-server configuration.
- [x] Add a data-only `DatabaseProvisioningPlan` that derives per-website database/user names, localhost-only account host, database-scoped grant target and a non-secret secret reference; add manual-check validation tests.
- [x] Implement optional website database/user creation with explicit database-scoped privileges, random credentials encrypted in a root-only secret store, and metadata-only secret references. [ ] VM-test grants, encryption, rollback and cross-website isolation. Password rotation and controlled credential delivery remain planned.
- [x] Add strict reusable DNS-domain normalization and CI tests; runtime verification remains outstanding.
- [x] Add additive metadata and one-time phpMyAdmin handoff-token storage with hash-only token persistence.
- [x] Add the root-owned credential broker, strict ownership checks, encrypted-secret decryption, audit event, isolated `spanel-pma` PHP-FPM pool/socket and session directory, plus a short-lived signon cookie.
- [x] Require an active SPanel administrator session on every phpMyAdmin request through an internal Nginx authorization subrequest; clear stale PMA cookies on SPanel login/logout and invalidate sessions for disabled admins.
- [x] Add phpMyAdmin package/configuration and Nginx routing to the first-install script; configure documented signon authentication and a per-database **Open phpMyAdmin** POST action.
- [ ] VM-test phpMyAdmin signon, cookie scope/expiry, logout, token replay/expiry, disabled-admin handling, broker permissions and cross-website isolation. Direct signon must fail closed on any authorization or secret-store error.
- [ ] Add cron jobs with validation and audit logs.
- [ ] Add file manager with strict path containment and permission checks.
- [ ] Add logs, disk usage and resource summaries.
- [ ] Add GitHub deployment with deployment history and rollback.

## Stage 6 — Hosting services
- [ ] Issue and renew SSL certificates; show expiry and errors.
- [x] Document the minimum manual backup/restore and interrupted-operation recovery procedure; this is preparation only, not an automated backup system. See `docs/BACKUP-RESTORE-AND-RECOVERY.md`.
- [ ] Implement automated panel/site/database backups with retention, encryption and integrity checks.
- [ ] Test restores, not just backup creation.
- [ ] Add email accounts only after reviewing deliverability, spam prevention, SPF, DKIM, DMARC and abuse controls.
- [x] Add local service status monitoring with a five-minute systemd timer and a panel health page; VM verification remains pending. [ ] Add remote health monitoring and alerts. [x] Add a read-only audit log UI with exact event/target filters and pagination. [ ] Add integration tests and verify audit coverage/retention in the Ubuntu VM.

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
- [x] Add baseline response headers (MIME sniffing, clickjacking, referrer policy, browser permissions, private no-store caching, and HTTPS-only HSTS).
- [x] Expire inactive administrator sessions after 30 minutes and rotate session IDs periodically. [ ] Add login rate limiting, review secure-cookie behavior behind proxies, MFA and dependency review.
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
