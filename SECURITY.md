# Security policy and development boundaries

- This project is for local development until a security review is complete.
- Never expose the panel to the public internet during development.
- Never commit local database credentials, tokens, or live website secrets.
- Never pass user-provided values to a shell command.
- Future privileged operations must use an allowlisted API with least privilege, strict validation, audit logging, atomic configuration writes, service config tests, and rollback.
- Production readiness requires rate limiting, HTTPS-only cookies, security headers, tested backups, dependency review, and a security review.
