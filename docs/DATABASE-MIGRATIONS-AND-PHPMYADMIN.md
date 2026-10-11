# Database migrations and implementation plan

## Goal

Evolve the initial SPanel schema safely without losing existing data. All schema changes must be versioned, repeatable, auditable and tested before any production VPS migration.

This document describes the implemented CLI migration runner and current migrations, plus the remaining verification and feature work. The runner and migrations have not yet been tested against a real MariaDB instance in the Ubuntu VM.

## Migration file layout

Use ordered, immutable SQL files, for example:

- `database/migrations/202610120001_create_core_resources.sql`
- `database/migrations/202610120002_add_operation_jobs.sql`

The migration ID is derived from the filename and stored with a SHA-256 checksum in `schema_migrations`. Once a migration has been applied anywhere, do not edit it; add a new migration instead.

Each migration should:
- Have a unique, sortable ID.
- Include only one coherent change.
- Be safe for the supported MariaDB version.
- Declare expected preconditions and postconditions in comments or adjacent tests.
- Avoid destructive changes unless a separately reviewed migration and explicit recovery procedure exist.

MariaDB DDL may implicitly commit, so the runner must not pretend every schema change can be rolled back with a transaction. Use additive changes, backups and carefully ordered steps.

## Migration runner requirements

Create a CLI-only entry point such as `bin/migrate.php`.

1. Refuse to run through HTTP and check the expected PHP runtime.
2. Load the database connection from the restricted local configuration.
3. Acquire a database/advisory lock so two migration processes cannot run at once.
4. Verify the migrations directory and filenames; sort migrations deterministically.
5. Create `schema_migrations` if needed. (Implemented in `bin/migrate.php`.)
6. For each migration, compare the file checksum with the recorded checksum. Stop immediately if an already-applied migration has changed.
7. Check prerequisites and whether the target change is already present, as appropriate for the migration's designed recovery strategy.
8. Apply one migration at a time, recording it only after successful completion and verification.
9. If a migration fails, stop, release the lock, report the migration ID and a redacted error, and preserve logs without credentials.
10. Provide `--status` to list applied/pending migrations and `--dry-run` to show planned work without changing the database. A `--target` option may be added for controlled development use, but should not permit unsafe downgrade.
11. Do not implement automatic destructive rollback. Recovery is via a verified backup and a forward-fix migration unless a migration has a specifically reviewed inverse operation.
12. Exit non-zero on any failure so the installer/updater cannot report success incorrectly.

## Migration sequence

### Migration A — Establish server ownership and multiple domains

Preflight:
- Inspect current websites and domains for invalid values and duplicate normalized domains.
- Ensure a local server row exists and can be identified without guessing.
- Check for websites with `server_id IS NULL` and report them before making ownership mandatory.
- Back up the panel database and verify that the backup can be read.

Change:
- Create `website_domains` with website foreign key, normalized domain, domain type, primary flag, status, timestamps and indexes.
- Copy each existing `websites.domain` into `website_domains` as its primary domain.
- Fail with a clear report if normalization causes collisions; never discard or overwrite a domain silently.
- Attach legacy websites without a server only after the administrator confirms the correct local server.
- Add new code to read and write the domain relation while retaining the legacy column for a compatibility period.
- Only remove the legacy column in a later migration after no application code relies on it.

Validation:
- Every non-archived website has exactly one primary domain.
- Each normalized domain is assigned to at most one website.
- Every website has a valid hosting server.
- Existing domain-to-website mappings remain unchanged.

### Website database provisioning plan (data-only)

Implemented in `app/DatabaseProvisioningPlan.php`, with validation in
`tests/database_provisioning_plan_validation.php`. The plan derives identifiers
from numeric server/website IDs, restricts the database account to `localhost`,
limits the grant target to that website's database, defines a small explicit
privilege allowlist, and emits only a non-secret `secret_ref`. It never creates
a password, executes SQL, or changes the server. The manual-only PHP workflow
includes this validation test but has not been run.

This is preparation only: the privileged helper continues to reject
`create_database: true`. Do not enable database creation until protected secret
storage and retrieval, password generation/rotation, exact MariaDB grants,
partial-failure recovery, and cross-website isolation have been implemented and
verified on the disposable Ubuntu VM.

### Migration B — Website database metadata and ownership

- Keep database metadata associated with a website and hosting server.
- Enforce name/user uniqueness in the correct scope (server, not globally, for multi-server support).
- Store only database identifiers and non-secret metadata; never store plaintext passwords.
- Provisioning credentials must be generated securely, shown once or stored as protected secret references, and never logged.
- Ensure each website's database user has grants only to its own database(s).

### Migration C — Background operation jobs

Implemented as `database/migrations/202610110002_create_operation_jobs.php`; runtime migration and recovery tests remain outstanding.

- Add a table for queued/running/succeeded/failed/rollback-required/cancelled jobs.
- Include operation type, owning server/website, request actor, attempt count, timestamps, redacted error summary and non-secret validated payload/result.
- Add indexes for workers selecting queued jobs and administrators filtering by status/date.
- Use an idempotency key where retries could otherwise duplicate an operation.
- Never store arbitrary shell command strings.

### Migration D — Database secret references and phpMyAdmin handoff

Implemented as `database/migrations/202610110004_prepare_phpmyadmin_handoffs.php`; apply and recovery tests remain outstanding.

- Add `website_databases.status` and a non-secret `secret_ref` field. The referenced secret must live outside the web root and panel metadata database.
- Add `phpmyadmin_handoffs`, storing only a SHA-256 token hash, admin/database ownership, expiry, consumed timestamp and audit-relevant remote address.
- The migration and token storage class do not enable phpMyAdmin login on their own. A reviewed authentication adapter, protected credential store and authorized launch endpoint are still required.
- Never store raw handoff tokens or database passwords in the database.

### Later feature migrations

Add tables when the corresponding feature is ready:
- DNS provider connections/zones/records only when API integration is implemented.
- SSL certificate metadata when certificate automation is implemented.
- Backup metadata when backup jobs and retention are implemented.
- Cron, deployment and resource-usage records with their features.
- phpMyAdmin does not require its own table per website; the shared one-time handoff table is `phpmyadmin_handoffs`.

## phpMyAdmin plan

- Install and maintain one supported phpMyAdmin instance per hosting server, rather than one copy per website.
- Do not expose it as an unrestricted public database login page by default. Provide an authenticated SPanel entry point with secure session handling and HTTPS; additionally restrict access by network/IP or VPN where practical. Each database row must have an **Open phpMyAdmin** link that signs the administrator in as that website's database user and selects the database using the reviewed one-time handoff design in `docs/PHPMYADMIN-DIRECT-LOGIN.md`.
- Avoid passing database passwords in URLs, query strings, browser storage or logs. Store the credential encrypted in a protected root-owned secrets store, with its key kept separately, so the secure handoff can authenticate without asking for the password every time.
- The selected website's database user should only have privileges for its own database. SPanel administrator access must be an explicit, separately authorized action.
- Use a dedicated supported authentication integration or a short-lived server-side session/credential handoff that does not leak secrets to the browser. Do not invent a home-grown passwordless bridge without reviewing phpMyAdmin's supported authentication mechanisms.
- Keep phpMyAdmin patched and limit upload/import size and execution time safely.
- If secure SPanel integration is not ready, make phpMyAdmin accessible only through a private/admin-restricted URL and require the administrator to authenticate normally.
- Database creation should work even if phpMyAdmin is unavailable; phpMyAdmin is a management interface, not the database engine.

## Website creation wizard — database option

During website creation:
1. Checkbox: **Create a database now** (on by default, but can be unticked).
2. If enabled, generate a safe unique database name and least-privilege database username based on a stable website ID plus a readable prefix.
3. Generate a strong random password using a cryptographically secure random generator.
4. Show the password once with a copy action, and state that it will not be recoverable in plain text. Offer password rotation if lost.
5. Provision the database/user through the restricted operations service, with narrowly scoped SQL privileges.
6. Store database name, username, server and status in metadata; store no plaintext password.
7. Show connection details, a PHP connection example that reads credentials from a private config file outside the public document root, and an **Open phpMyAdmin** action that logs in as the database user and selects that database without exposing credentials in the URL.
8. Test connection and ownership; if any step fails, show the exact failed step and safely clean up only resources created by this failed job.
9. On website removal, never silently drop the database. Offer archive/backup and a separate explicit destructive confirmation.

For a website that needs more than one database, allow additional databases from its management area with the same ownership and audit rules.

## phpMyAdmin deployment and access tests

Before calling this complete, verify:
- phpMyAdmin is not accessible to unauthenticated public visitors.
- HTTPS is enforced.
- Session/CSRF protections are effective and credentials do not appear in URLs or logs.
- A website's database user cannot read, alter or drop another website's database.
- Admin database access requires explicit authorization and is audited.
- Import/export works within configured limits.
- phpMyAdmin security updates can be applied without affecting website databases.
- The feature fails closed if SPanel authentication or the access handoff fails.

## Required test matrix

Run on a disposable Ubuntu VM and a fresh test database:
- Fresh install with no existing schema.
- Migration against the current starter schema with zero websites.
- Migration with several websites and different domains.
- Duplicate domains that collide after normalization (must stop without losing data).
- Websites with missing/invalid server assignments (must stop for administrator resolution).
- Migration interrupted mid-step, then recovery according to documented procedure.
- Already-applied migration with matching checksum.
- Already-applied migration with altered checksum (must refuse).
- Two concurrent migration attempts (only one proceeds).
- Backup and restore before and after migrations.
- Database provisioning failure at each step; no unrelated resources are deleted.
- phpMyAdmin one-click login, replay/expiry protection, access control and cross-website database isolation.

## Definition of done

- Migration runner is CLI-only, locked, checksum-aware and returns reliable exit codes.
- Forward migrations preserve existing data and have documented recovery steps.
- Core schema constraints and data ownership are tested.
- Website creation can optionally create a database and restricted user.
- phpMyAdmin is installed/configured securely and the selected website's database isolation is verified.
- Nothing is marked production-ready until these tests pass in the Ubuntu VM.
