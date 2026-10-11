# SPanel backup, restore and recovery preparation

This guide is for the private Ubuntu Server 24.04 development VM. SPanel does **not yet have an automated backup/restore manager**. Do not treat a successful backup command as proof that a restore works.

## Before changing the VM

1. Shut down the VM cleanly and take a named VirtualBox snapshot before installing or making risky changes.
2. Keep the VM on NAT and do not expose the panel or phpMyAdmin to the public internet.
3. Keep at least one separate copy of anything you need to retain. A VirtualBox snapshot is convenient for development but is not an independent backup.
4. Never put backups under `/opt/spanel/public`, a website document root, or another web-accessible directory.

## What must be backed up together

- SPanel metadata database, including admins, server/website records, migration history, operation jobs, health snapshots and audit events.
- `/opt/spanel/config/config.local.php` and the matching application release/source needed to interpret it.
- `/etc/spanel/database-secrets.key` **and** the encrypted files under `/var/lib/spanel/secrets/`. The key and ciphertext must be restored as a matching set; the key alone or ciphertext alone is not a usable backup.
- Website document roots under `/var/www/spanel/sites/`.
- Relevant generated Nginx site files and per-site PHP-FPM pool files, plus SPanel's own Nginx/PHP-FPM configuration if recovering the complete VM.
- Any website database dumps, if the websites contain data that must be retained.

phpMyAdmin signon session files are short-lived authentication state and should **not** be restored. Do not include active session files in a backup intended for routine recovery.

## Private manual metadata backup

Run from a root shell on the VM. Replace the output directory only with a new directory outside all web roots:

```bash
sudo install -d -o root -g root -m 0700 /root/spanel-backups
sudo sh -c 'umask 077; mariadb-dump --single-transaction --routines --triggers spanel > /root/spanel-backups/spanel-metadata.sql'
sudo install -o root -g root -m 0600 /opt/spanel/config/config.local.php /root/spanel-backups/config.local.php
sudo install -o root -g root -m 0600 /etc/spanel/database-secrets.key /root/spanel-backups/database-secrets.key
sudo tar --numeric-owner --acls --xattrs -cpf /root/spanel-backups/website-secrets.tar -C /var/lib/spanel secrets
sudo tar --numeric-owner --acls --xattrs -cpf /root/spanel-backups/site-files.tar -C /var/www/spanel sites
sudo chmod 0600 /root/spanel-backups/*
```

These commands are a **manual starting point**, not a complete backup solution. They do not dump each hosted website database, archive all generated service configuration, encrypt the backup for off-device storage, or verify that a restore succeeds. Confirm each command's exit status and resulting file sizes. Do not copy the unencrypted backup to cloud storage or a shared folder.

For each hosted database, create a separate dump using an appropriately privileged local database administrator, after confirming the database name from SPanel metadata. Do not grant the panel application user global database privileges just to make backups easier.

## Restore principles

1. Restore only to a private, trusted VM/server and stop SPanel's worker and health-check timers before changing files or metadata.
2. Restore the metadata database, the matching local config, the encryption key and encrypted secret directory as a consistent set. Preserve root ownership and restrictive permissions.
3. Restore website files and each website database only after checking the target paths and database names. Never overwrite unrelated resources.
4. Verify ownership, permissions, Nginx/PHP-FPM configuration, database grants, migration checksums and service status before restarting timers.
5. Run `php /opt/spanel/bin/doctor.php`, `php /opt/spanel/bin/migrate.php --status`, and `php /opt/spanel/bin/health-check.php` as the `spanel` account. Investigate every failure before allowing queued work to run.
6. Test website requests and phpMyAdmin authorization again. Do not restore phpMyAdmin signon sessions or old SPanel session files.
7. Keep the pre-restore snapshot until the restored system has been inspected.

## Interrupted provisioning and removal

- Run `php /opt/spanel/bin/reconcile-operations.php` first. It is read-only by default.
- Only use `--apply` after reviewing the affected job IDs and inspecting the server; the tool marks stale jobs for manual recovery and does not retry or remove resources.
- Never retry a job marked `rollback_required` until its partial state has been reconciled.
- SPanel intentionally has no automatic website/database deletion yet. Do not manually delete generated Linux users, document roots, Nginx files, PHP-FPM pools or databases until a dedicated removal workflow supports an explicit confirmation, a recorded backup/retention decision, dependency checks, an audit trail and tested rollback/recovery.

## Not yet production-ready

Automated backup scheduling, retention and pruning, encrypted off-device storage, per-website database dumps, backup integrity verification and restore drills remain unfinished. Do not migrate live websites until those capabilities and recovery procedures have been implemented and tested.
