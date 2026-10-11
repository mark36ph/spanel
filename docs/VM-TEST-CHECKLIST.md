# Ubuntu VM acceptance checklist

Use this checklist only on a **disposable private Ubuntu Server 24.04 LTS VM**, not a production VPS. Take a VirtualBox snapshot before installation and keep networking on NAT.

## 1. Install

- [ ] Check out/copy the latest `main` branch into the VM.
- [ ] From the repository root, review and run `sudo bash install/ubuntu-vm-install.sh`.
- [ ] Confirm the installer creates the panel DB/configuration, applies migrations, registers the local server, configures Nginx/PHP-FPM, and prompts for the administrator.
- [ ] Confirm the installer finishes its HTTP smoke test and read-only doctor check without errors.
- [ ] Confirm the installer rejects an unreplaced `CHANGE_ME` DB password or incorrect generated base URL.
- [ ] Confirm `/config/config.local.php`, `/config/config.example.php`, `/database/schema.sql` and `/bin/doctor.php` do not return HTTP `200`.
- [ ] In VirtualBox, forward host TCP `8081` to guest TCP `80`.

## 2. Verify services and database

Run on the VM:

```bash
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
sudo systemctl --no-pager --full status nginx "php${PHP_VERSION}-fpm" mariadb
sudo -u spanel php /opt/spanel/bin/doctor.php
sudo -u spanel php /opt/spanel/bin/migrate.php --status
curl -sS -o /dev/null -w 'Login HTTP status: %{http_code}\n' http://127.0.0.1/login.php
```

- [ ] Nginx, PHP-FPM and MariaDB are active.
- [ ] Doctor reports `PASS` with no unexpected warnings.
- [ ] Every migration is marked applied and there are no checksum errors.
- [ ] The login endpoint returns HTTP `200`.

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
- [ ] Open a website and confirm its recent operation history matches the Operations page.
- [ ] Confirm none of these screens implies that a queued job has changed the server.

## 5. Test the website queue (metadata only)

- [ ] Create one disposable test website using a reserved test domain such as `vm-test.example.test`.
- [ ] Confirm a website record and a `website.provision` operation appear.
- [ ] Confirm the operation is auditable and visible on the Operations page.
- [ ] **Do not expect a live website yet.** The privileged `spanel-ops` helper is intentionally missing; the worker should fail closed instead of making server changes. Do not repeatedly retry the same job.

## 6. Record findings

For each failure, capture the exact command, output, page URL and relevant log excerpt. Avoid sharing passwords or the contents of `config/config.local.php`.

Useful logs:

```bash
sudo tail -n 100 /var/log/nginx/spanel_error.log
sudo journalctl -u nginx -n 100 --no-pager
PHP_VERSION="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
sudo journalctl -u "php${PHP_VERSION}-fpm" -n 100 --no-pager
sudo journalctl -u mariadb -n 100 --no-pager
```

## Not yet acceptance-tested

Real per-site Linux users/PHP-FPM pools, website Nginx configuration, hosted-site database creation, secure phpMyAdmin direct login, SSL, backups/restores and safe panel updates/rollback are not implemented end to end. Keep SPanel private and do not migrate Projectabe or FactBurstQuiz until those features and their isolation/recovery tests pass.
