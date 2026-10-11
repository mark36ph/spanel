# SPanel architecture

## Purpose

SPanel is a self-hosted control panel for managing multiple websites from one interface. It starts on a private Ubuntu Server VM, then moves to a Fasthosts VPS after installation, update, recovery and hosting workflows have been tested. The design must allow additional websites and, later, additional hosting servers without a rewrite.

## Main components

- **Web panel:** PHP application served by Nginx and PHP-FPM. It handles administrator login, forms, status pages, validation and audit views.
- **Metadata database:** MariaDB stores administrators, hosting servers, website records, resource associations, update history and audit events. Website content and website databases remain separate from panel metadata.
- **Installer:** A command-line setup program for fresh Ubuntu VM/VPS installations. It checks prerequisites, creates restricted service accounts, initialises configuration and schema, configures Nginx/PHP-FPM, and creates the first administrator.
- **Privileged operations service:** A small local service/helper with a narrow allowlist of operations such as provision website, validate/reload Nginx, manage a database, and apply a verified panel release. The PHP-FPM user must not run as root and must not have arbitrary shell access.
- **Hosting nodes:** The first installation registers itself as the local node. The data model records which node owns each website. Additional nodes can be added later through authenticated, encrypted communication and explicitly provisioned credentials.
- **Release updater:** A restricted updater stages a reviewed release, backs up panel state, applies migrations, switches releases, performs health checks, and rolls back code if checks fail.

## Website lifecycle

1. Administrator enters a website name and one or more domains.
2. SPanel validates domain syntax, conflicts, reserved paths, selected server and requested options.
3. SPanel creates a pending operation and audit event.
4. The local operations service creates a dedicated Linux account, document root and PHP-FPM pool for that website.
5. It generates an Nginx site configuration from a fixed template, tests it with the Nginx configuration test command, and only then enables/reloads it.
6. A health check verifies the site configuration. Failed provisioning is reported clearly and either rolled back or left in a recoverable, recorded pending/error state.
7. The website appears in the central inventory and opens its own management area.

No step may build a shell command by concatenating browser input. Domain names, paths, usernames, database names and PHP versions must be validated against explicit allowlists and constraints.

## Target data model

The agreed target schema, table responsibilities, constraints, DNS approach and legacy migration path are specified in `docs/DATABASE-DESIGN.md`. The initial schema is a starter and must evolve through versioned migrations rather than destructive edits on a live install.

- **Administrators:** identities, password hashes, active state and last login.
- **Servers:** stable server ID, display name, hostname, environment, health/maintenance state and capability/version information. Secrets and private keys live in restricted local configuration, not in ordinary metadata rows.
- **Websites:** stable website ID, owning server ID, display name, Linux account, document root, PHP runtime, lifecycle state and timestamps.
- **Domains:** a separate relation for primary domains, aliases and subdomains, each mapped to a website. A website may have multiple domains; domains must be unique where appropriate.
- **Website databases:** database names and restricted database users associated with a website. Passwords must be stored securely or shown once at creation, not logged.
- **Other resources:** mailboxes, cron jobs, certificates, backup policies, deployment records and resource-operation history reference a website and, where relevant, its server.
- **Schema migrations:** migration ID, checksum and application timestamp.
- **Audit events:** actor, action, target, outcome, timestamp and minimal non-secret metadata.

Website-owned resources should have explicit ownership/foreign keys. Deleting a website must not silently delete backups or irreversibly destroy databases; destructive actions require confirmation, a backup/retention policy, and an audit record.

## Isolation and server operations

- Each website runs under a dedicated unprivileged Linux account and its own PHP-FPM pool.
- Website files, temporary files, logs and credentials must not be readable by unrelated website accounts.
- Database grants are limited to the owning website's database(s).
- Nginx configuration is generated from fixed templates and validated before reload; updates use atomic writes and keep the last known-good configuration.
- Long-running operations use job records with states such as queued, running, succeeded, failed and rollback-required. The UI polls job status rather than holding a web request open while system changes run.
- The privileged service accepts a typed operation and validated fields, not a command string or arbitrary filesystem path. Requests are authenticated, locally restricted, rate-limited where applicable, and audit logged.
- Server credentials are not embedded in PHP, JavaScript, HTML, Git, or logs.

## Install and update lifecycle

- The same CLI installer should support a clean development VM and a clean production VPS.
- Configuration, website files and databases are separate from versioned panel code.
- The panel update button only offers approved releases from the fixed official repository. It does not accept a repository URL or arbitrary branch from the browser.
- Before an update, the updater checks disk space and compatibility, acquires a lock, backs up the panel database and configuration, stages the release separately, verifies integrity, runs checks/migrations, switches release atomically and runs a health check.
- A failed health check returns to the previous code release and displays recovery instructions. Database migrations need explicit compatibility and recovery planning; code rollback alone is not enough if a migration is destructive.
- Production defaults to stable, reviewed release tags. Direct pushes to main are the development workflow, but must not automatically run on the live VPS.

## Panel navigation

- **Server administration:** overview, servers, health, services, global security, panel updates, audit log and system backups.
- **Website inventory:** search/filter all websites, their server, domain, state and resource usage; add a website.
- **Website management:** selecting a website opens a dedicated context and sidebar for overview, domains, files, databases, email, cron jobs, SSL, backups, logs, Git deployment and settings.
- Server-level actions remain separate from website-level actions to avoid confusing global changes with changes to one website.

## Delivery order

1. Verify the current PHP/MariaDB foundation in the Ubuntu VM.
2. Implement the repeatable CLI installer and migration runner.
3. Add the website/server/domain data model through a forward migration.
4. Implement a safe job/operations boundary and provision one test website.
5. Add the website-specific UI and resource modules incrementally.
6. Implement staged panel updates and rehearse upgrade failure/rollback.
7. Test backup restore and migration of Projectabe and FactBurstQuiz before production cutover.
