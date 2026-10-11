# SPanel database design

Status: target design for review and implementation through versioned migrations. The current `database/schema.sql` is an initial scaffold, not yet the final production schema. Do not destructively edit an installed database; test every migration on a disposable database/VM first.

## Design principles

- MariaDB/InnoDB, `utf8mb4`, foreign keys, explicit indexes and UTC timestamps.
- The panel database stores control-plane metadata, not hosted website content or application data.
- A website belongs to exactly one hosting server. Multiple domains and multiple resources belong to a website.
- Use stable numeric IDs internally; store normalized domains in lowercase/IDNA ASCII form.
- Keep passwords, API tokens, private keys and SSH credentials out of ordinary database rows, source control and logs. Store secrets in restricted root-owned configuration or a dedicated secrets facility; DB rows may hold a non-secret `secret_ref`.
- Prefer soft-deactivation/archival for managed resources. Never cascade-delete website databases, backups or audit history as a side effect of deleting a website.
- Long-running system changes are jobs. The PHP web process never runs as root or submits arbitrary shell commands.
- Schema changes are immutable, ordered migrations recorded in `schema_migrations`; checksums detect modified migrations.

## Core tables

### `admins`
Panel administrators only.
- `id` primary key.
- `email` unique, normalized for comparisons.
- `password_hash`; `is_active`; `created_at`; `last_login_at`.
- Future additions: MFA state and recovery metadata, stored securely.

### `servers`
One row per hosting node, including the initial local VM/VPS.
- `id` primary key; `name`; unique `hostname`.
- `environment` (development/production); `status` (pending/online/offline/maintenance/error).
- `is_local`; optional public/private IPv4 and IPv6 addresses.
- `panel_version`, optional `agent_version`, `capabilities_json`, `last_heartbeat_at`, timestamps.
- Credentials and private keys are never stored here. Enforce only one local server in application logic and verify that invariant during installation.

### `websites`
One independently isolated website installation.
- `id` primary key; `server_id` required foreign key to `servers`.
- `name`; unique generated `system_user`; `document_root`; selected `php_version`.
- `status` (pending/active/disabled/error/archived); timestamps; optional `archived_at`.
- Keep filesystem paths generated/validated by SPanel, not arbitrary user-provided paths.
- A website has one server, one Linux identity and one PHP-FPM pool. Moving servers is a migration workflow, not a simple foreign-key edit.

### `website_domains`
A separate table so one website can have a primary domain, aliases and subdomains.
- `id` primary key; `website_id` foreign key; `domain` normalized and globally unique.
- `domain_type` (primary/alias/subdomain); `is_primary`; `status` (pending/active/disabled); timestamps.
- The application must enforce exactly one primary domain per non-archived website within a transaction. Do not rely only on a nullable unique index for this rule.
- Unique domain ownership prevents the same host name being assigned to two websites. Validate syntax, IDNA, trailing dots, length, and conflicts before writing.

### `website_databases`
Metadata for databases owned by a website; the actual MariaDB databases are provisioned separately.
- `id`; `website_id` foreign key; `database_name`; `database_user`; `engine`; `status`; timestamps.
- Database names and usernames unique within the hosting server, not necessarily globally across multiple servers. Use unique keys on (`server_id`, `database_name`) and (`server_id`, `database_user`) or derive the server through the website in application logic; avoid pretending names are globally unique in a multi-server design.
- Passwords are never stored in plaintext or logs. Prefer generated credentials shown once and rotated on request; if later persistence is required, store a secret reference, not the secret itself. Database/user names are unique within the hosting server; provision users with grants limited to their own website database(s). See `docs/DATABASE-MIGRATIONS-AND-PHPMYADMIN.md`.
- Record server ownership explicitly or use a constraint-safe design that guarantees a database belongs to the same server as its website.

### `schema_migrations`
- `migration_id` primary key; SHA-256 `checksum`; `applied_at`.
- Applied migration files are immutable. New changes get a new migration ID. Migration runner must stop if an already-applied migration's checksum differs.

### `audit_events`
Append-only record of security- and administration-relevant actions.
- `id`; nullable `admin_id`; `event_type`; `target_type`; `target_id`; outcome; timestamp; optional remote address; minimal JSON details.
- Never include passwords, tokens, private keys, full connection strings or other secrets in details.
- Admin deletion should not erase audit history; retain the event and set the actor reference to null if necessary.

## Operational tables

### `operation_jobs`
Tracks provisioning and other work that may take longer than an HTTP request.
- `id`; optional `server_id`, `website_id`; `operation_type`; `status` (queued/running/succeeded/failed/rollback_required/cancelled).
- Validated non-secret `payload_json`; safe `result_json`; `error_code` and redacted `error_summary`.
- `requested_by_admin_id`; `attempt_count`; `created_at`; `started_at`; `finished_at`; optional `idempotency_key`.
- Worker claims jobs atomically, limits retries, and uses allowlisted typed operations. Never persist arbitrary shell command strings.

### `website_cron_jobs` (implement when cron support is built)
- `id`; `website_id`; validated schedule; executable/script path constrained to that website; enabled state; last/next run metadata.
- Do not accept arbitrary system paths or run jobs as root.

### `website_certificates` (implement with SSL support)
- `id`; `website_id`; certificate type/provider; covered-domain metadata; status; issued/expiry timestamps; last error code.
- Private key material stays in restricted filesystem storage, never in the metadata database.

### `website_backups` (implement with backups)
- `id`; `website_id` nullable if retained after website archival; backup type; storage reference/path; checksum; size; status; created/expiry timestamps.
- Do not delete backups automatically just because a website is archived or removed.

### `website_deployments` (implement with Git deployment)
- `id`; `website_id`; source/revision metadata; status; start/end timestamps; redacted logs/reference; rollback reference.
- Do not store Git tokens in deployment rows or logs.

## phpMyAdmin access

Install one maintained phpMyAdmin instance per hosting server, not a copy per website. SPanel should offer a secure **Open phpMyAdmin** action for the selected website database, while keeping database credentials out of URLs and browser storage. Default access should require SPanel authentication plus HTTPS and practical network/IP restrictions; use a supported, reviewed authentication handoff. The website database user must not be able to access other websites' databases. Explicitly authorized administrator access is separate and audited. phpMyAdmin is optional management UI: database provisioning must still work when it is unavailable. See `docs/DATABASE-MIGRATIONS-AND-PHPMYADMIN.md`.

## DNS model

The first release should not need DNS-provider credentials or maintain a local authoritative DNS service. It should generate the records needed for a website and provide manual instructions plus public DNS checks.

If/when an existing DNS provider's API is integrated, add provider-neutral tables:
- `dns_provider_connections`: provider name, account/zone identifiers, enabled state and a non-secret `secret_ref`; never the API token itself.
- `dns_zones`: zone name, provider connection, authoritative/provider identifier, sync status and timestamps.
- `dns_records`: zone, record name/type, normalized value, TTL, priority where relevant, provider record identifier and sync status.
- Add uniqueness rules appropriate to the provider; DNS permits multiple records with the same owner/type in many cases, so do not impose a simplistic unique key on name alone.
- Treat provider state as external authority. Store only a managed mirror, show diffs before changes, avoid overwriting unrelated records, and record sync failures. DNS checks can be transient results rather than permanent rows unless history becomes useful.
- Never persist registrar credentials. Registrar delegation/glue records are outside SPanel unless the registrar explicitly offers a suitable API.

## Relationships and deletion policy

- `servers 1 — many websites`.
- `websites 1 — many website_domains`, databases, jobs, cron jobs, certificates, backups and deployments.
- Jobs and audit history survive website archival.
- Prefer `RESTRICT` for core ownership relationships and use an explicit archive/removal workflow. Physical deletion is an exceptional maintenance operation after checking dependencies and backup retention.
- If a server is removed from service, mark it offline/retired only after its websites/resources have been migrated or intentionally archived.

## Migration from the current starter schema

The current starter schema has `websites.domain` and a unique domain index, and it permits `websites.server_id` to be null. The target model requires a separate `website_domains` table and a required server assignment.

Use a forward migration, not a destructive schema rewrite:
1. Ensure a local server row exists and identify it deterministically during installation.
2. Create `website_domains` and its indexes/foreign keys.
3. Copy each existing `websites.domain` into `website_domains` as its primary domain, after validating and normalizing values; stop and report conflicts rather than silently dropping them.
4. Ensure every website has a valid `server_id`; attach legacy null assignments to the intended local server only after explicit verification.
5. Update application reads/writes to use `website_domains`; maintain a compatibility period if required.
6. Only in a later migration, after the old column is no longer used, remove `websites.domain` and its unique index.
7. Add operation/resource tables as their features are implemented, not all speculative tables on day one.

## Implementation order

1. Agree this target model.
2. Inspect all existing PHP queries/forms to identify schema dependencies before changing columns.
3. Add migration runner and automated schema tests.
4. Implement and test the core migration on a fresh database and a database containing representative legacy rows.
5. Add jobs and resource tables incrementally alongside each feature.
6. Do not mark the schema implementation complete until migrations, constraints, upgrade path and backup/restore have been tested in the Ubuntu VM.
