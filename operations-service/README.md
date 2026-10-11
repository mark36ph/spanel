# SPanel operations service (first stage)

This Go service is intentionally **read-only and unprivileged**. It is a first integration step, not a website provisioner.

## Current supported request

- `website.preflight`: collects installed PHP 8.2/8.3 CLI/FPM indicators, available disk space, conflicts with deterministic website resource names, and whether MariaDB is listening on loopback TCP port 3306.
- `website.provision`: rejected with `operation_not_enabled`. No system state is changed.

Nginx configuration validity and DNS-to-server matching are returned as `null` (unknown), not guessed. A TCP listener check only establishes that a port is listening; it does not authenticate to MariaDB or prove database creation permissions.

## Build and manual VM installation

Build on the Ubuntu 24.04 VM after installing Go:

```bash
cd operations-service
go build -trimpath -buildvcs=false -o /tmp/spanel-ops .
sudo install -d -o root -g root -m 0755 /usr/local/lib/spanel-ops
sudo install -o root -g root -m 0755 /tmp/spanel-ops /usr/local/lib/spanel-ops/spanel-ops
sudo install -o root -g root -m 0644 ../install/systemd/spanel-ops.socket /etc/systemd/system/spanel-ops.socket
sudo install -o root -g root -m 0644 ../install/systemd/spanel-ops.service /etc/systemd/system/spanel-ops.service
sudo systemctl daemon-reload
sudo systemctl enable --now spanel-ops.socket
```

The `spanel` account must exist before enabling the socket. The socket is root-owned and group-accessible only to `spanel`; the service process uses systemd's dynamic unprivileged identity. Do not add the panel account to sudoers for this service.

Inspect status and logs with:

```bash
sudo systemctl status spanel-ops.socket spanel-ops.service --no-pager
sudo journalctl -u spanel-ops.service -n 100 --no-pager
stat -c '%U %G %a %n' /run/spanel-ops/operations.sock
```

Do not install this on production until the Ubuntu VM acceptance checklist has been completed. The panel's current `OperationsServiceClient` uses this socket path, but the worker's provisioning path remains separate and fails closed when its fixed helper is missing.

## Security properties

- No shell, subprocess, SQL execution, file writes, service reloads, account creation or privilege escalation.
- Only one bounded newline-delimited JSON request per connection; unknown or missing fields are rejected.
- Fixed resource paths are derived from a positive numeric website ID; clients cannot supply paths or commands.
- Socket activation is provided by systemd. The daemon refuses to run as root.
- Provisioning remains disabled until the future write-capable component receives a separate operation-by-operation security review, ownership/rollback design and VM acceptance tests.
