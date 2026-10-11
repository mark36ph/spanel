# Installer and updater design

## Goals

SPanel must be installable from the command line on both the development Ubuntu VM and the eventual Ubuntu VPS. The same installer should be repeatable and documented. The panel must also be able to offer controlled updates from the project's GitHub repository.

## Command-line installation

Target interface (planned; not implemented yet):

```bash
# Download a reviewed installer from the official SPanel repository, inspect it,
# then run it with sudo on a supported Ubuntu Server installation.
sudo bash install.sh
```

The published installation instructions must give the exact verified download URL and release/ref. Do not tell users to execute an unreviewed remote script directly from a pipe.

The installer should:
1. Check OS/version, root privileges, network access, free disk space and required commands before making changes.
2. Install only required packages (Nginx, PHP-FPM/extensions, MariaDB client/server as appropriate, Git and supporting tools).
3. Create a dedicated panel system account and directories with restrictive ownership/permissions; keep the web document root pointed at `public/`.
4. Configure MariaDB with a dedicated least-privilege application user. Never put real credentials in Git.
5. Create a local configuration file from the example, generate strong secrets locally, and set safe permissions. Do not overwrite existing config during reinstall/update.
6. Apply versioned database migrations and record the installed version.
7. Create the first administrator using hidden password input (or another secure prompt); never put passwords in shell arguments or logs.
8. Configure and validate Nginx/PHP-FPM, then reload only after configuration tests pass.
9. Run a health check and print the local panel URL plus the next steps. The development VM must remain private; do not open firewall/router ports automatically.
10. Be safe to re-run: detect an existing installation, preserve configuration/database/backups, and require an explicit recovery path before destructive actions.

A dry-run/preflight mode and clear logs should be added before production use. Installer errors must stop safely and explain recovery steps.

## In-panel updates

The Updates page should show the currently installed version, latest approved version, release notes, and whether a backup/preflight has passed. Applying an update requires an explicit administrator action and a clear confirmation.

Update flow:
1. Query the configured official repository/release endpoint using a fixed repository identity; never accept a repository URL or branch from a browser request.
2. Fetch release metadata and verify the approved release/ref or artifact integrity before applying it.
3. Acquire an update lock so two updates cannot run simultaneously.
4. Create and verify backups of the panel database and local configuration; preserve website files and website databases.
5. Stage the new release in a separate directory; do not update the running code in place.
6. Run automated checks and versioned database migrations. Migrations must be designed to be forward-safe and have a documented recovery strategy.
7. Switch to the new release atomically, run a health check, and restore the previous release if the check fails.
8. Record actor, release, result and timestamps in the audit log; keep logs free of secrets.

## Privilege boundary

The PHP-FPM user must not be root and must not be able to run arbitrary commands. The web UI should call a narrow local updater service or root-owned helper with a fixed allowlist of operations. It must validate the requested approved release again on the privileged side. The helper must not accept arbitrary paths, shell fragments, repository URLs, or commands.

The updater's credentials must not be stored in web-accessible files or committed to Git. For this private repository, prefer a read-only deploy key available only to the update service. Never embed a GitHub token in PHP, JavaScript, HTML, logs or a URL.

## Development and production policy

- In the VM, test install, reinstall, update, failed update, rollback and restore using a disposable snapshot first.
- For development, allow a clearly labelled development channel only when explicitly configured; production defaults to approved stable releases/tags.
- Do not automatically deploy every commit from `main` to the production VPS. The in-panel button should apply an approved release after preflight/backup and administrator confirmation.
- The updater is a planned feature. Until implemented and tested, updates must be performed manually using documented Git/CLI steps.
