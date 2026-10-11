# Direct phpMyAdmin database access

## User experience

Every database listed under a website's SPanel **Databases** page should have an **Open phpMyAdmin** link beside its name. After SPanel has created the database and its dedicated database user, clicking the link should open phpMyAdmin already authenticated as that user, with the correct database selected. The user should not need to type the database username or password again.

The link is available only to an authenticated SPanel administrator authorized to manage that website. It is not a public URL and must not contain credentials.

## Required flow

1. During database creation, SPanel generates a strong random database password and provisions the database/user with least-privilege grants.
2. **Implemented in the current provisioner:** the password is generated inside the root-owned helper and stored as authenticated AES-GCM ciphertext outside the web root in `/var/lib/spanel/secrets`. The decryption key is in `/etc/spanel/database-secrets.key`; both are root-owned and inaccessible to the panel account. The credential broker that permits phpMyAdmin to retrieve a credential for an authorized one-time handoff is not implemented yet.
3. The database metadata row stores only the database name, username, owning website/server, status and a non-secret secret reference.
4. When the administrator clicks **Open phpMyAdmin**, SPanel re-checks the administrator session, CSRF/intent as appropriate, website/database ownership and server status.
5. SPanel creates a cryptographically random, short-lived, single-use handoff token. Store only its hash with the intended database/user, requesting admin, expiry and consumed state.
6. Keep the raw handoff token only in the server-side authenticated session for the short launch flow, then redirect the browser to a same-origin SPanel launch endpoint. The endpoint removes the token from the session and consumes it once, then performs a server-side handoff using phpMyAdmin's supported authentication integration (prefer a reviewed signon integration or equivalent documented mechanism). Never put the raw token in HTML, JavaScript, query strings, referrer data, browser storage or logs. If server-side session storage is unavailable, fail closed rather than passing the token through the browser.
7. phpMyAdmin authenticates as the website's database user and opens the intended database. The user sees normal phpMyAdmin UI and can browse tables, run SQL and import/export within configured limits.
8. The handoff token is invalidated on use or expiry. Audit the access event without logging credentials or tokens.

## Authentication and secrets requirements

- Use phpMyAdmin's documented, supported authentication mechanism; do not patch its core or create an undocumented passwordless login.
- Never pass a database password in a URL, query string, HTML, JavaScript, local storage or logs.
- Use HTTPS and secure, HttpOnly, appropriately SameSite cookies; validate same-origin return targets to prevent open redirects.
- Restrict the launch endpoint to authenticated and authorized SPanel administrators. Require re-authentication for privileged administrator-level database access where appropriate.
- The website database user must only access that website's database(s). It must not have global privileges or access to other websites' databases.
- The root-only encrypted secret store and key are implemented by the restricted website/database helper. The controlled credential-retrieval broker and phpMyAdmin authentication adapter remain outstanding; the web application must not be given direct read access to the key or encrypted files.
- Handoff tokens must be random, single-use, short-lived, bound to the intended admin/database and protected against replay and CSRF. Keep the raw token only in server-side session storage during the launch flow; persist only its hash in MariaDB.
- Until the credential broker and phpMyAdmin integration are implemented and tested, keep the **Open phpMyAdmin** action disabled. If the secret store, SPanel authorization, token validation or phpMyAdmin integration is unavailable, fail closed and show a useful error. Do not silently fall back to an unauthenticated phpMyAdmin login.
- phpMyAdmin should be installed once per hosting server and kept patched. Use HTTPS and, where practical, restrict access by VPN/IP/firewall.
- Provide a separate explicit administrator database access path if needed. Do not silently elevate the website user to global database privileges.

## Database password rotation

If a website database password is rotated:
- Update the MariaDB account and secret store as one controlled job.
- Test the new credential before reporting success.
- Update the stored secret only after the intended account change is verified; on failure, record a recoverable state and do not leak either password.
- Audit the rotation and invalidate outstanding handoff tokens.
- Show the new password once if the administrator requested it; do not offer recovery of the old password in plaintext.

## Test requirements

- Clicking the database's link opens phpMyAdmin already authenticated and with the correct database selected.
- Opening the link for Website A cannot access Website B's database.
- Expired, replayed, tampered or wrong-admin handoff tokens fail closed.
- Passwords and tokens never appear in browser URLs, referrers, HTML, JavaScript, logs or analytics.
- Access fails after admin logout/deactivation or authorization revocation.
- Import/export and SQL actions work within limits permitted for that database user.
- A broken secrets store or phpMyAdmin service returns a safe error and does not expose credentials.
- Session cookies and HTTPS settings are verified in the Ubuntu VM before marking the feature complete.
