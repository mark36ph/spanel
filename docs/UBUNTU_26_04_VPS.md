# Ubuntu 26.04 VPS installation (private preview)

The installer `install/ubuntu-vps-install.sh` is a separate adaptation of the Ubuntu 24.04 VM installer. It has **not** yet passed acceptance testing on a clean Ubuntu 26.04 VPS. Do not treat this as production-ready hosting.

## Design

- Only Ubuntu 26.04 is accepted; PHP 8.5 is required.
- The SPanel administration Nginx listener binds to `127.0.0.1:80` only, not the public interface.
- The existing panel base URL remains `http://127.0.0.1:8081`, reached via an encrypted SSH tunnel.
- The installer does not modify SSH daemon configuration or UFW rules.
- Existing SPanel installations are refused, rather than overwritten.
- The current file-operations helper is **not installed**; upload/edit/delete are not yet enabled.
- The installer still provisions the worker, MariaDB, phpMyAdmin and the restricted website provisioner. These components require a live Ubuntu 26.04 acceptance test before deployment.

## Check before running

On the VPS, with the repository cloned:

```bash
cd ~/spanel
git pull --ff-only origin main
bash -n install/ubuntu-vps-install.sh
git status --short
```

Do **not** run the installer until the Ubuntu 26.04 package behavior, PHP tests, Nginx configuration, and privileged helper have been reviewed. The installer makes substantial root-level system changes and has no automated rollback.

## After an accepted installation

From Windows PowerShell, keep this tunnel running:

```powershell
ssh -i "$env:USERPROFILE\.ssh\spanel_vps" -L 8081:127.0.0.1:80 spadmin@YOUR_VPS_IP
```

Then visit `http://127.0.0.1:8081` on that Windows PC. Do not publish the SPanel admin listener, even though the firewall already permits HTTP/HTTPS for future hosted sites.

## Still outstanding

- Fresh-VPS acceptance test for Ubuntu 26.04, PHP 8.5 and phpMyAdmin.
- Production-grade HTTPS / public panel access design, if ever needed.
- Complete restricted file operations with audit logging.
- Backup/restore and safe updater/rollback.
- Provisioned website behavior, isolation and SSL verification on VPS.
