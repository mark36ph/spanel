# Direct phpMyAdmin database access

## Current implementation

The website management page now renders **Open phpMyAdmin** for eligible active databases on the local server. The form uses POST and CSRF protection. The administrator's raw handoff token stays in the server-side SPanel session and is never put in a browser URL, HTML, JavaScript or log.

The installer now installs phpMyAdmin without enabling Apache, configures a dedicated non-root `spanel-pma` PHP-FPM pool and Nginx route, and enables phpMyAdmin's documented `signon` authentication mode. The SPanel application and phpMyAdmin use separate PHP-FPM identities. The root-owned broker is the only component that can read the encrypted database secret store and key.

## Handoff flow

1. The authenticated administrator submits **Open phpMyAdmin** from a website's Databases table. SPanel validates CSRF, website/database ownership, active status, local server assignment and the expected database/user identifiers.
2. SPanel generates a random 256-bit handoff token, stores only its SHA-256 hash in `phpmyadmin_handoffs`, and keeps the raw token in the server-side SPanel session for at most 60 seconds.
3. The browser is redirected to a same-origin launch endpoint. It revalidates the SPanel login, consumes the pending session entry and sends a bounded JSON request to the fixed root-owned broker through an exact sudoers rule. The token is not included in the redirect URL.
4. The broker atomically consumes the token, verifies the administrator remains active, checks that the database and website are active and belong to the local server, validates the non-secret metadata reference, and confirms the token's client address binding.
5. The broker decrypts the AES-256-GCM credential in memory and writes the signon values to a short-lived PHP session file under `/var/lib/spanel/phpmyadmin-sessions`. The file is root-owned, group-readable/writable only by `spanel-pma`, and mode `0660`. The panel account cannot read the directory.
6. The broker returns only the random signon session ID and database name. SPanel sets an HttpOnly, SameSite=Strict cookie scoped to `/phpmyadmin/` with a 60-second lifetime and redirects to phpMyAdmin with the selected database name.
7. phpMyAdmin's supported signon mode reads the session and authenticates as the site's dedicated localhost-only database user. The database name in the URL is not a credential; neither the database password nor handoff token appears in the URL.
8. The broker writes an audit event containing database metadata only. Broker errors are generic and do not log tokens or credentials. phpMyAdmin logout clears the signon cookie.

## Security boundaries

- The panel account never reads the key or encrypted secret files and never receives the plaintext database password in the broker response.
- The broker accepts no command-line arguments, has a strict bounded request contract, validates IDs and database ownership, and is installed root-owned with a narrow sudoers rule.
- The phpMyAdmin PHP-FPM pool runs as `spanel-pma`, not `spanel`; it has no sudo rule. The signon session directory is root-owned and inaccessible to the panel account.
- The broker accepts only a short-lived, single-use token bound to the administrator, database and remote address. The token is consumed before credentials are made available to phpMyAdmin.
- phpMyAdmin is configured for a fixed local server with arbitrary-server access disabled. Each database user has grants scoped to its own database and no `GRANT OPTION`.
- On the private HTTP-only VM, the cookie's Secure flag is disabled because HTTPS is not configured. Do not expose this installation publicly. Production use must configure HTTPS and regenerate/review the phpMyAdmin cookie configuration.
- Database passwords and raw tokens must never be written to logs, browser storage, HTML, JavaScript or URL parameters. If broker, secret-store or authorization checks fail, signon must fail closed.

## Remaining work

This integration is implemented in code but has **not been built or VM-tested**. Verify session file permissions, expiry, logout, token replay, disabled-admin handling, database ownership and cross-website isolation using the checklist. SSL, DNS automation, password rotation and safe database backup/removal flows remain separate roadmap items.
