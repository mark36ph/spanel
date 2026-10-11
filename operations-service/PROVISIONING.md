# Restricted website provisioner (initial release)

This is the root-owned typed helper invoked by the existing PHP worker at `/usr/local/sbin/spanel-ops`. It is separate from the read-only socket service. It is intentionally limited to provisioning a single website without a database.

## Supported behavior

- Accepts one JSON object on stdin, capped at 16 KiB; no command-line arguments are accepted.
- Accepts only the exact `website.provision` request contract used by SPanel.
- Derives all usernames, resource names and paths from the positive numeric website ID. The caller cannot supply paths, commands, service names, SQL or resource names.
- Selects PHP-FPM 8.3 then 8.2 unless a supported version is explicitly requested; verifies the runtime configuration before continuing.
- Refuses any existing site user, document root, pool configuration or Nginx site/link rather than overwriting it.
- Creates a dedicated Linux user and document root, stages a fixed PHP-FPM pool and Nginx template, tests both configurations and reloads only the fixed PHP-FPM and Nginx services.
- Attempts bounded best-effort rollback of only resources created by that invocation on failure. It never recursively deletes directories.
- Returns the exact successful response expected by the PHP worker. Errors return a non-zero exit code and a generic machine-readable code; diagnostics are not exposed to the panel.

## Explicit limitations

**Database creation is not enabled.** Requests with `create_database: true` are rejected before any changes. The safe database workflow still needs a credential-generation and one-time display/storage design, strict MariaDB grants, and recovery tests. SSL issuance is also not part of this helper yet. Do not tell users a database or certificate was created by this initial release.

The PHP-FPM and Nginx service reloads and rollback are not yet VM-acceptance-tested. Keep this helper disabled until the complete checklist has passed on a disposable Ubuntu Server 24.04 VM.

## Build and stage in the VM

From the repository root, after installing Go on the disposable VM:

```bash
go build -trimpath -buildvcs=false -o /tmp/spanel-ops-provisioner ./operations-service/cmd/provisioner
sudo install -o root -g root -m 0755 /tmp/spanel-ops-provisioner /usr/local/sbin/spanel-ops
sudo install -d -o root -g root -m 0755 /var/www/spanel/sites
sudo install -o root -g root -m 0440 install/sudoers/spanel-ops /etc/sudoers.d/spanel-ops
sudo visudo -cf /etc/sudoers.d/spanel-ops
sudo stat -c '%U %G %a %n' /usr/local/sbin/spanel-ops /etc/sudoers.d/spanel-ops
```

Verify the binary is root-owned and not writable by group/others. Do not enable it on production or migrate real websites before VM acceptance. Do not add any broader sudo rule for the panel account.

## Recovery caveat

Rollback is best-effort, not a transaction across Linux accounts and system daemons. If a failure occurs, inspect the job state and protected system logs before retrying. Never rerun a failed provisioning job blindly. Any leftover resources must be inspected manually and reconciled with the SPanel database before another attempt.
