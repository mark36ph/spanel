# Restricted website and database provisioner

This is the root-owned typed helper installed at `/usr/local/sbin/spanel-ops`. The SPanel PHP worker can invoke only this fixed helper through the exact sudoers rule. The helper accepts one bounded JSON request on stdin and no command-line arguments.

## Implemented behavior

- Accepts only the exact `website.provision` request contract. It rejects missing/extra fields, duplicate JSON keys, invalid scalar types, trailing JSON values, single-label domains and unsupported PHP versions.
- Derives all usernames, database identifiers and paths from the numeric website ID. Browser-supplied paths, SQL, commands, service names and resource names are never accepted.
- Selects PHP-FPM 8.3 then 8.2 unless a supported version is explicitly requested, and validates the chosen FPM configuration.
- Refuses pre-existing users, site paths, pool files, Nginx files/links and existing database secret files rather than overwriting them.
- Creates a dedicated non-login Linux account, isolated document root and PHP-FPM pool, plus a fixed-template Nginx site. Configurations are tested before activation/reload.
- Writes a basic `index.html` landing page so a new site can be tested before uploading real files.
- Performs a local HTTP health check using the requested domain in the Host header and verifies the response belongs to the newly created landing page.
- When `create_database: true`, creates a per-site MariaDB database and localhost-only account. Grants are limited to that database and exclude global privileges and `GRANT OPTION`.
- Generates a strong random database password in the privileged helper and stores it only as AES-256-GCM authenticated ciphertext in `/var/lib/spanel/secrets/website-db-<id>.enc`. The 32-byte key is held in `/etc/spanel/database-secrets.key`; both are root-owned and inaccessible to the panel account. The password is never returned in the helper response or stored in panel metadata/logs.
- Attempts best-effort rollback of only resources created by the current invocation if a later step fails. It never recursively deletes directories.
- Returns a strict response that the PHP worker validates before committing website/database metadata.

## Installation

The first-install script builds the helper from `operations-service/cmd/provisioner`, installs it root-owned, creates the protected secret-store paths/key, installs phpMyAdmin without Apache, configures a separate `spanel-pma` PHP-FPM pool, installs the root-owned credential broker, validates and installs `install/sudoers/spanel-ops`, and enables the non-root `spanel-worker.timer`. The timer checks the operation queue every 30 seconds; each worker run handles at most one job.

The installer targets a clean, disposable Ubuntu Server 24.04 LTS VM. It has not yet passed VM acceptance testing. GitHub Actions remain manual-only and must not be started automatically during development.

## Important limitations

- Database creation and website setup are implemented in code but **not yet built or VM-tested**. Do not use this on a production host.
- The SPanel site page and launch endpoint now use a short-lived, single-use handoff with phpMyAdmin's documented signon mode. The root-owned broker validates the active admin, local server, website/database ownership and token binding, then writes credentials only to a root-owned session file accessible to the separate `spanel-pma` pool. It returns no database password to the SPanel application.
- Nginx checks the SPanel session and active-admin state on every phpMyAdmin request using an internal-only auth endpoint. Panel and phpMyAdmin session directories, FPM identities and sockets are isolated; logout clears both phpMyAdmin cookies. This flow is code-complete but not yet VM-tested.
- phpMyAdmin signon, the broker's session permissions, cookie expiry/logout and cross-site isolation still require VM acceptance tests. SSL issuance, DNS automation, website suspension/removal, backups/restores and safe update/rollback are not enabled end-to-end.
- The helper's rollback is best-effort, not a transaction across Linux accounts, MariaDB and system daemons. If a job is marked `rollback_required`, inspect the host before any retry.

## Manual helper inspection

On the disposable VM after installation:

```bash
sudo stat -c '%U:%G:%a %n' /usr/local/sbin/spanel-ops /usr/local/sbin/spanel-pma-credential-broker /etc/sudoers.d/spanel-ops
sudo stat -c '%U:%G:%a %n' /etc/spanel/database-secrets.key /var/lib/spanel/secrets /var/lib/spanel/phpmyadmin-sessions
sudo stat -c '%U:%G:%a %n' /etc/phpmyadmin/conf.d/99-spanel.php /etc/php/$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')/fpm/pool.d/spanel-pma.conf
sudo visudo -cf /etc/sudoers.d/spanel-ops
sudo systemctl status spanel-worker.timer
sudo journalctl -u spanel-worker.service -n 100 --no-pager
```

Never print or share the contents of the encryption key or any encrypted credential file. Do not retry a failed/uncertain job until its system resources and metadata have been reconciled.
