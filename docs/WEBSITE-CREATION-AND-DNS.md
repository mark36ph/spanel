# Website creation and server/DNS configuration

## Goal

Make it possible to add a website to SPanel through one guided workflow, while keeping the first version free of paid registrar or DNS-provider dependencies. SPanel is for personal hosting initially, so it should be useful with domains the administrator already owns.

## Website creation wizard

### Step 1 — Basic details
- Website display name.
- One primary domain and optional aliases/subdomains.
- Target hosting server (default to the local server).
- PHP version chosen from versions actually installed on that server.
- Optional creation from a Git repository, disabled until Git deployment is implemented.

### Step 2 — Preflight checks
Show a clear pass/warning/fail result for:
- Domain syntax and conflicts with other websites.
- Server online state, disk space and installed PHP runtime.
- Required services and permissions.
- DNS readiness, when it can be checked publicly.
- Whether the domain currently points to the selected server.

A DNS mismatch should normally be a warning, not a blocker: the administrator may want to prepare a site before changing DNS.

### Step 3 — Resources and options
- Create an isolated Linux account, document root and PHP-FPM pool.
- Optionally create a website database and restricted database user.
- Choose whether to request SSL after DNS is pointing correctly.
- Show what will be created and any destructive implications before confirmation.

### Step 4 — Provisioning job
- Create an auditable queued job; do not keep the browser request open during system operations.
- Use the restricted operations service, never arbitrary shell commands from PHP.
- Generate Nginx configuration from a fixed template and validate it before enabling/reloading.
- Record each operation's status. On failure, show the failed step and recovery guidance; restore the last known-good configuration.
- Show progress and the final website URL in the panel.

### Step 5 — Completion checklist
Display:
- Website state and document root.
- Domains and current DNS checks.
- Required DNS records with copyable values.
- SSL status and next action if issuance is not yet possible.
- Database credentials only at creation time where applicable.
- Links to the website's own management sidebar.

## Provisioning worker contract (current implementation status)

The metadata-only wizard queues `website.provision`. The CLI worker is a separate process and invokes only the fixed path `/usr/local/sbin/spanel-ops` through non-interactive sudo; it never accepts a command, filesystem path, SQL statement or service name from the job payload. The worker checks that the job, website and server assignments match and requires the server to be marked local.

**Provisioning is not enabled by the panel installer.** An initial restricted Go helper now exists at `operations-service/cmd/provisioner`, but it must be manually built, installed root-owned, and given the exact narrow sudoers rule on the disposable Ubuntu VM before the worker can invoke it. It supports a website with no database only; `create_database: true` is rejected. If the helper is missing, not executable, rejects the request, exits unsuccessfully or returns a mismatched response, the worker fails the job rather than pretending the website was created. Do not install a broader sudo rule or create a placeholder helper that returns success.

A separate data-only contract defines the read-only preflight operation `website.preflight`. Its request contains the website ID, normalized domain, optional PHP version, database choice and required disk space. Its response must echo the website ID/domain and provide exact typed facts; SPanel evaluates these facts and refuses to build a provisioning plan if a blocking check fails. The contract and step planner do not execute commands or modify the server.

The data-only workflow planner defines this fixed order: create site identity and directories; stage and validate PHP-FPM; optionally create the database/user; stage and validate Nginx; enable/reload Nginx; then health-check the site. Every step has a named rollback/recovery action. The plan explicitly reports `execution_enabled: false` until a reviewed restricted service exists.

The helper's narrow JSON request contract is:
- `operation`: exactly `website.provision`
- `website_id`: positive integer from the queued website record
- `domain`: the website's validated primary domain
- `php_version`: `null`, `8.2` or `8.3`
- `create_database`: boolean

A successful response must be JSON containing `ok: true`, the same `website_id` and `domain`, a supported actual `php_version` (`8.2` or `8.3`), and a boolean `database_created` that exactly matches the request. If `php_version` was explicitly requested, the returned version must match it; if it was `null`, the helper must select an installed supported runtime and return the version it selected. The worker derives database names from the numeric website ID (`spw_<id>` and `spu_<id>`); it never trusts resource names supplied by the helper or browser. The helper must reject extra/missing fields, oversized input, malformed domains, unsupported versions and all unrecognized operations. It must use fixed command arguments and templates, validate every generated path, and never evaluate a shell command. The worker caps the helper response at 16 KiB, applies a 60-second output timeout and discards helper stderr rather than exposing diagnostics in the panel.

The initial privileged implementation now covers Linux identity, document-root permissions, PHP-FPM pool staging/testing, fixed-template Nginx staging/testing, activation and best-effort rollback. It rejects resource collisions and arbitrary command-line arguments. It does not create databases or issue SSL certificates. Its behavior, service reloads, permissions and failure recovery must be reviewed and tested on a disposable Ubuntu VM before it is installed or enabled in production. Rollback is best-effort across operating-system resources, not a true transaction; inspect any failed job before retrying.

## Domain and DNS design

### Keep registration separate
SPanel will not become a domain registrar for personal use. It manages domains already owned by the administrator; buying, renewing and transferring domain registrations remains with the registrar.

### Provider-neutral DNS support
DNS control should use a provider-adapter design rather than assume that every registrar also hosts DNS:
1. **Manual mode first:** display the exact records to add at the current DNS provider, with copy buttons and validation/check status.
2. **Optional API adapters later:** where the existing DNS provider offers a suitable API, allow the administrator to configure a restricted token and manage records from SPanel. Keep credentials outside the website and panel source trees; never log or display them again after saving.
3. **Provider-independent checks:** resolve public DNS records and compare them with the intended values. Explain that DNS propagation and caching mean a change may not appear immediately.

Typical website records include:
- A record for the domain pointing to the server's IPv4 address.
- AAAA record only if IPv6 is configured and reachable.
- CNAME for appropriate subdomains such as `www`, where supported by the zone layout.
- TXT records for verification and services.
- MX records only when mail hosting is configured.

SPanel must not overwrite unrelated DNS records. Before an API-driven change, show a diff and require confirmation for potentially disruptive edits.

### Nameservers
Nameservers and DNS records are related but not interchangeable:
- **Using the current provider's nameservers:** the simplest default. SPanel shows the required records and can use an API adapter if supported.
- **Custom nameservers:** a later optional feature. It requires authoritative DNS service on reachable servers, redundant DNS for reliability, firewall/network configuration, and registrar-side nameserver delegation and glue records where required. SPanel cannot make registrar-side delegation happen unless the registrar exposes an API.
- Do not make running our own authoritative DNS a prerequisite for hosting websites.

## Security and safety requirements

- Never allow browser input to become an arbitrary command, config path or service name.
- Validate domains, PHP versions, paths and generated resource names against strict rules.
- Keep provider tokens and service credentials in restricted configuration or a secrets facility, never in Git, HTML, JavaScript, ordinary audit metadata or logs.
- Use least-privilege credentials and only request the DNS permissions needed.
- Audit changes with actor, target, outcome and timestamp, excluding secrets.
- Use dry-run/preflight and explicit confirmation before changing public DNS or removing a website.
- Keep website files and databases separate from panel metadata. Website deletion must not silently destroy backups or databases.

## Initial implementation order

1. Finalise the versioned server/website/domain schema, including a separate domains table.
2. Implement manual DNS guidance and validation; do not depend on a provider API.
3. Implement a provisioning job model and restricted operations service.
4. Provision a disposable test website on the Ubuntu VM.
5. Add DNS API adapters only when the user's existing provider is identified and its API is suitable.
6. Consider custom authoritative nameservers only if they provide a real benefit for personal hosting.
