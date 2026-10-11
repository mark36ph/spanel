# Ubuntu VM acceptance checklist

Use this checklist only on a **disposable private Ubuntu Server 24.04 LTS VM**, not a production VPS. Take a VirtualBox snapshot before installation and keep networking on NAT.

## 1. Install

- [ ] Check out/copy the latest `main` branch into the VM.
- [ ] From the repository root, review and run `sudo bash install/ubuntu-vm-install.sh`.
- [ ] Confirm root is used for system setup, then the installer creates a dedicated `spanel` account with a non-login shell and nonzero UID.
- [ ] Confirm the installer refuses to adopt an already-existing `spanel` account and stops rather than trusting its identity.
- [ ] Confirm application files are owned by root, the panel account cannot modify `bin/worker.php`, and `config/config.local.php` is `root:www-data` mode `0640`.
- [ ] Confirm `/etc/php/<version>/fpm/pool.d/spanel.conf` configures `user = spanel`, `group = www-data`, and `/run/php/spanel.sock`; Nginx uses that socket.
- [ ] Confirm PHP-FPM workers for the `spanel` pool run as the non-root `spanel` user; the normal PHP-FPM master process may remain root as required by systemd.
- [ ] Confirm the installer creates the panel DB/configuration, applies migrations, registers the local server, configures Nginx/PHP-FPM, and prompts for the administrator.
- [ ] Confirm the installer builds and installs the root-owned `/usr/local/sbin/spanel-ops` and `/usr/local/sbin/spanel-pma-credential-broker` helpers and validates `/etc/sudoers.d/spanel-ops`.
- [ ] Confirm phpMyAdmin is installed without Apache and uses a dedicated `spanel-pma` PHP-FPM pool/socket, not the SPanel `spanel` pool.
- [ ] Confirm the phpMyAdmin FPM socket is owned by `www-data`, grouped to `spanel-pma`, mode `0660`; Nginx can connect but the `spanel` account cannot connect directly.
- [ ] Confirm `/var/lib/spanel/panel-sessions` is `spanel:spanel` mode `0700`, and `spanel-pma` cannot read it.
- [ ] Confirm `/var/lib/spanel/phpmyadmin-sessions` is `root:spanel-pma` mode `0770`, and the `spanel` account cannot list/read it.
- [ ] Confirm `/etc/phpmyadmin/conf.d/99-spanel.php` is readable by `spanel-pma` but not by `spanel`; the phpMyAdmin service account must not be able to read `/opt/spanel/config/config.local.php`.
- [ ] Confirm `/etc/spanel/database-secrets.key` is `root:root` mode `0600`, and `/var/lib/spanel/secrets` is `root:root` mode `0700`.
- [ ] Confirm `spanel-worker.timer` is enabled and active; its oneshot worker runs as non-root `spanel`.
- [ ] Confirm `spanel-health-check.timer` is enabled and active and its oneshot service runs as non-root `spanel`.
- [ ] Confirm the installer finishes its HTTP smoke test and read-only doctor check without errors.
- [ ] Confirm the installer rejects an unreplaced `CHANGE_ME` DB password or incorrect generated base URL.
- [ ] Confirm `/config/config.local.php`, `/config/config.example.php`, `/database/schema.sql` and `/bin/doctor.php` do not return HTTP `200`.
- [ ] In VirtualBox, forward host TCP `8081` to guest TCP `80` for SPanel.
- [ ] Optionally add a second NAT forward: host TCP `8082` to guest TCP `80` for testing a hosted website in a browser. Add `127.0.0.1 vm-test.example.test` to the Windows hosts file, then browse to `http://vm-test.example.test:8082`. Remove the hosts-file entry when finished; no public DNS is needed.

## 2. Verify services and database

Run on the VM:

```bash
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
sudo systemctl --no-pager --full status nginx "php${PHP_VERSION}-fpm" mariadb
sudo -u spanel php /opt/spanel/bin/doctor.php
sudo -u spanel php /opt/spanel/bin/migrate.php --status
sudo systemctl --no-pager --full status spanel-worker.timer spanel-health-check.timer
sudo stat -c '%U:%G:%a %n' /usr/local/sbin/spanel-ops /etc/sudoers.d/spanel-ops /etc/spanel/database-secrets.key /var/lib/spanel/secrets
curl -sS -o /dev/null -w 'Login HTTP status: %{http_code}\n' http://127.0.0.1/login.php
curl -sS -o /dev/null -w 'phpMyAdmin unauthenticated status: %{http_code}\n' http://127.0.0.1/phpmyadmin/
```

- [ ] Nginx, PHP-FPM and MariaDB are active.
- [ ] Doctor reports `PASS` with no unexpected warnings.
- [ ] Every migration is marked applied and there are no checksum errors.
- [ ] The login endpoint returns HTTP `200`.
- [ ] Unauthenticated `/phpmyadmin/` returns HTTP `302` to SPanel sign-in rather than exposing a database login or returning a server error.
- [ ] A direct browser/curl request to `/phpmyadmin-auth-check.php` returns HTTP `404`; the internal authorization endpoint must not be publicly callable.

## 3. Verify panel access

On the Windows host, open `http://127.0.0.1:8081`.

- [ ] The login page renders without PHP errors.
- [ ] The administrator can sign in and reach the dashboard.
- [ ] The Servers page shows **Local Ubuntu VM** as online/local.
- [ ] The Websites and Operations pages load.
- [ ] Signing out returns you to the login page.
- [ ] An unauthenticated request to `/dashboard.php` redirects to the login page.
- [ ] Requests to `/config/config.local.php` and `/database/schema.sql` do not expose files.

## 4. Test inventory, audit and operation visibility

- [ ] Search websites by name, domain and numeric ID; confirm no-match and clear-filter states.
- [ ] Filter websites by each lifecycle status and confirm results are accurate.
- [ ] Search servers by name/hostname and filter by status/environment.
- [ ] Open Audit log, filter by event type and target type, and verify pagination and HTML escaping.
- [ ] Confirm the dashboard shows server count, queued jobs, recovery-required jobs and recent jobs.
- [ ] Open **Server health** and confirm the latest Nginx, MariaDB, PHP-FPM, worker timer and disk-space checks appear.
- [ ] Run `sudo -u spanel php /opt/spanel/bin/health-check.php`; confirm it updates the health page and logs statuses without changing server configuration.
- [ ] Stop one disposable service, run the health check and confirm the page shows a critical status; restart it and confirm the next scheduled check recovers.
- [ ] Confirm results older than 10 minutes display as stale/unknown rather than healthy.
- [ ] Open a website and confirm its recent operation history matches the Operations page.
- [ ] Confirm none of these screens implies that a queued job has changed the server.

## 5. Test actual website provisioning

- [ ] Create one disposable test website using a reserved test domain such as `vm-test.example.test`. This domain is for local Host-header testing; no public DNS record is required.
- [ ] Tick **Create a website database** for one test website.
- [ ] Confirm the job appears in Operations and the `spanel-worker.timer` processes it within about a minute.
- [ ] Confirm the job reaches `succeeded`, the website status becomes active, and the primary domain status becomes active.
- [ ] Confirm the site user `spw<ID>` has a non-login shell and is not shared with another website.
- [ ] Confirm `/var/www/spanel/sites/<ID>/public/index.html` exists and is owned by the site user with Nginx-readable group permissions.
- [ ] From the VM, run `curl -sS -H 'Host: vm-test.example.test' http://127.0.0.1/` and confirm the response says **SPanel site ready** and includes the selected domain.
- [ ] Confirm the matching PHP-FPM pool and Nginx site exist, `php-fpm -t` and `nginx -t` pass, and only the expected site is enabled.
- [ ] Confirm the database `spw_<ID>` and account `spu_<ID>@localhost` exist when the checkbox was selected.
- [ ] Verify the account has grants only on its own database. Confirm it has no global grants and no `GRANT OPTION`.
- [ ] Confirm `website_databases` contains the database name/user, `active` status and non-secret `website-db:<ID>` reference, but no password.
- [ ] Confirm the secret file exists at `/var/lib/spanel/secrets/website-db-<ID>.enc`, is root-owned mode `0600`, and the `spanel` account cannot read the file or key.
- [ ] Do not print the key or encrypted secret contents in logs or share them.
- [ ] Create a second disposable website and verify its Linux user, PHP-FPM pool, document root, database and database account are distinct.
- [ ] Test collision handling by requesting a duplicate domain/resource/database ID and confirm existing resources are never overwritten or deleted.
- [ ] Test invalid domains (including single-label names), unsupported PHP versions, null/wrongly typed booleans, duplicate JSON keys, trailing JSON values, pre-existing usernames/paths, PHP-FPM config failure, Nginx config failure, MariaDB failure and reload failure.
- [ ] Confirm failures are recorded as failed or `rollback_required` and never retried automatically. Inspect any partial state manually before another attempt.
- [ ] From an active website's Databases table, click **Open phpMyAdmin** and confirm it opens phpMyAdmin authenticated as that website's database user with the correct database selected.
- [ ] Confirm the SPanel handoff token is absent from browser URLs, HTML, JavaScript, referrers and logs; the database password must never appear in the response or panel metadata.
- [ ] Confirm the broker response contains only `ok`, the random signon session ID and the non-secret database name; it must not contain a username/password pair or encrypted-secret contents.
- [ ] Confirm the signon session file is root-owned, group `spanel-pma`, mode `0660`, and unreadable by `spanel`. Confirm the browser cookie is HttpOnly, SameSite=Strict, scoped to `/phpmyadmin/`, and expires after 60 seconds.
- [ ] Confirm a consumed, expired, replayed or wrong-admin handoff fails closed. Confirm disabled admins, inactive websites/databases, remote servers and mismatched secret references are rejected.
- [ ] Confirm phpMyAdmin logout and SPanel logout clear both the signon and internal phpMyAdmin cookies.
- [ ] Confirm every phpMyAdmin request runs the internal SPanel auth check: logout, idle session expiry or admin deactivation must block the next phpMyAdmin request and redirect to SPanel sign-in.
- [ ] Confirm direct phpMyAdmin access without a valid SPanel session redirects to SPanel login.
- [ ] Confirm Website A's signon cannot access Website B's database and neither account has global grants or `GRANT OPTION`.
- [ ] On the local HTTP-only VM, record that Secure cookies are disabled only for this private test. Do not expose it publicly; production signon requires HTTPS.


## 6. Verify the read-only operations service (after building it in the VM)

- [ ] Build the read-only `operations-service` with the Go compiler; keep it separate from the privileged provisioner.
- [ ] Install the service and socket units using `operations-service/README.md`; confirm the socket is `root:spanel` mode `0660`.
- [ ] Confirm the daemon runs with a dynamic unprivileged identity and does not run as root.
- [ ] Send a valid `website.preflight` request from the panel account and confirm the returned facts are well-formed.
- [ ] Send malformed JSON, extra fields, an oversized frame, and an unsupported operation; confirm each is rejected without changing server state.
- [ ] Confirm the read-only socket service rejects `website.provision` with `operation_not_enabled` and does not change server state.
- [ ] Confirm the preflight clearly reports Nginx validation and DNS as unknown, and does not confuse a listening MariaDB port with verified credentials/permissions.
- [ ] Review service logs and confirm they contain no secrets.

## 7. Record findings

For each failure, capture the exact command, output, page URL and relevant log excerpt. Avoid sharing passwords or the contents of `config/config.local.php`.

Useful logs:

```bash
sudo tail -n 100 /var/log/nginx/spanel_error.log
sudo journalctl -u nginx -n 100 --no-pager
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
sudo journalctl -u "php${PHP_VERSION}-fpm" -n 100 --no-pager
sudo journalctl -u mariadb -n 100 --no-pager
sudo journalctl -u spanel-worker.service -n 100 --no-pager
sudo journalctl -u spanel-worker.timer -n 100 --no-pager
```

## Not yet acceptance-tested

The installer now prepares the restricted website/database helper, root-only encrypted credential store, automatic queue timer, phpMyAdmin package/configuration, isolated phpMyAdmin PHP-FPM pool and root-owned signon broker. These changes are implemented in code but not built or acceptance-tested in the Ubuntu VM yet. SSL, DNS automation, backups/restores and safe panel updates/rollback remain incomplete. Keep SPanel private and do not migrate Projectabe or FactBurstQuiz until installation, signon replay/expiry, cross-site isolation, failure recovery and restore tests pass.

## 7. Recovery and destructive-action safeguards

- [ ] Read [backup, restore and recovery preparation](BACKUP-RESTORE-AND-RECOVERY.md) before changing or removing VM resources.
- [ ] Take a named VirtualBox snapshot before installer, migration or provisioning failure tests.
- [ ] Create a private metadata/config/secrets/site-files backup using the documented manual starting point; verify command exit codes, ownership and file permissions.
- [ ] Confirm backups are outside all web roots and are not copied unencrypted to a shared/cloud location.
- [ ] On a disposable snapshot/clone, rehearse restoring metadata, the matching encryption key and encrypted secret files as a consistent set; do not restore phpMyAdmin or SPanel session files.
- [ ] Confirm SPanel offers no website/database delete action yet. Until the restricted removal workflow and rollback tests exist, do not manually delete generated users, files, Nginx/PHP-FPM configuration or databases.
- [ ] Confirm stale jobs are read-only by default and `rollback_required` jobs are never automatically retried or destructively cleaned up.
