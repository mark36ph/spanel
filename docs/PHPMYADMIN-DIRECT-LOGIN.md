# Direct phpMyAdmin database access

## User experience

Every database listed under a website's SPanel **Databases** page should have an **Open phpMyAdmin** link beside its name. After SPanel has created the database and its dedicated database user, clicking the link should open phpMyAdmin already authenticated as that user, with the correct database selected. The user should not need to type the database username or password again.

The link is available only to an authenticated SPanel administrator authorized to manage that website. It is not a public URL and must not contain credentials.

## Required flow

1. During database creation, SPanel generates a strong random database password and provisions the database/user with least-privilege grants.
2. The password is delivered once to a restricted secrets store outside the website document root and outside the panel metadata database. The secret is encrypted at rest, and its decryption key is held separately in root-readable configuration or another protected secret facility.
3. The database metadata row stores only the database name, username, owning website/server, status and a non-secret secret reference.
4. When the administrator clicks **Open phpMyAdmin**, SPanel re-checks the administrator session, CSRF/intent as appropriate, website/database ownership and server status.
5. SPanel creates a cryptographically random, short-lived, single-use handoff token. Store only its hash with the intended database/user, requesting admin, expiry and consumed state.
6. The browser is sent to a same-origin SPanel launch endpoint, which consumes the token once and performs a server-side handoff using phpMyAdmin's supported authentication integration (prefer a reviewed signon integration or equivalent documented mechanism). The handoff must not put the database password or token in query strings, referrer data, browser storage or logs.
7. phpMyAdmin authenticates as the website's database user and opens the intended database. The user sees normal phpMyAdmin UI and can browse tables, run SQL and import/export within configured limits.
8. The handoff token is invalidated on use or expiry. Audit the access event without logging credentials or tokens.

## Authentication and secrets requirements

- Use phpMyAdmin's documented, supported authentication mechanism; do not patch its core or create an undocumented passwordless login.
- Never pass a database password in a URL, query string, HTML, JavaScript, local storage or logs.
- Use HTTPS and secure, HttpOnly, appropriately SameSite cookies; validate same-origin return targets to prevent open redirects.
- Restrict the launch endpoint to authenticated and authorized SPanel administrators. Require re-authentication for privileged administrator-level database access where appropriate.
- The website database user must only access that website's database(s). It must not have global privileges or access to other websites' databases.
- Store the credential in a root-owned secrets store outside the web root, encrypted at rest with a separately protected key; the web application must not have unrestricted filesystem access. A narrowly scoped privileged broker may perform the handoff.
- Handoff tokens must be random, single-use, short-lived, bound to the intended admin/database and protected against replay and CSRF.
- If the secret store, SPanel authorization, token validation or phpMyAdmin integration is unavailable, fail closed and show a useful error. Do not silently fall back to an unauthenticated phpMyAdmin login.
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
