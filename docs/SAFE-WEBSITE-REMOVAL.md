# Safe website removal design

**Removal is not implemented yet.** The current SPanel interface does not offer a website/database delete action. Keep it that way until the workflow below can be enforced by the application and restricted helper.

## Required administrator workflow

1. Open the website overview and select **Request removal**. Do not expose a one-click permanent delete.
2. Require the administrator to type the website's primary domain and confirm the website ID.
3. Show every affected resource before confirmation: primary/alias domains, queued/running jobs, Linux site user, document root, PHP-FPM pool/socket, Nginx site, database/user, encrypted database secret, and any future cron/email/SSL resources.
4. Require an explicit choice:
   - **Retain backup**: create and verify a backup of website files and its database, with the retention location and expiry shown; or
   - **Retain resources**: remove the website from active service but keep its files/database until the administrator manually approves later cleanup.
   The UI must not imply a backup exists until a trusted backup operation has verified it.
5. Block removal while jobs are queued/running or any operation is marked `rollback_required`. Require manual reconciliation first.
6. Create a removal request with a unique ID and audit event. A preview/dry-run must list planned changes without modifying the server.
7. The restricted helper must revalidate the website ID, exact generated resource names, ownership, paths and database names. It must refuse symlinks, path escapes, shared resources, name collisions, unknown files, or any mismatch.
8. Disable traffic before removing resources. Verify Nginx/PHP-FPM configuration before reload; if validation fails, restore the prior configuration and stop.
9. Remove resources in an ordered, idempotent process with a recorded step/result for each resource. Do not automatically delete retained backups.
10. Record actor, confirmation, backup/retention choice, resource inventory, result and any partial failures in the audit log. If the result is uncertain, mark the request for manual recovery and never retry destructive steps automatically.

## Required acceptance tests

- Wrong domain/ID confirmation, CSRF failure, inactive admin and stale request are rejected.
- Active or recovery-required operation blocks removal.
- Backup failure or missing retention decision blocks destructive removal.
- Website A removal never changes Website B's user, files, pool, Nginx site, database or grants.
- Symlink/path traversal, pre-existing unexpected files, database-name mismatch and shared-resource collision fail closed.
- Nginx/PHP-FPM validation and reload failures leave a recoverable state.
- A crash after every removal step can be inspected and resumed safely without deleting unrelated resources.
- Audit logs and UI never expose database passwords, encryption keys or secret file contents.

Until every prerequisite and acceptance test is implemented, use the documented manual recovery procedure and do not delete website resources.
