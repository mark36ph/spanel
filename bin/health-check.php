<?php
declare(strict_types=1);

/**
 * Read-only system probes with latest results written to the SPanel metadata DB.
 * Run only from CLI as the unprivileged spanel account.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/app/Database.php';

function checkUnit(string $unit): array
{
    $descriptors = [
        0 => ['file', '/dev/null', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ];
    $process = @proc_open(['/usr/bin/systemctl', 'is-active', '--quiet', $unit], $descriptors, $pipes, null, ['PATH' => '/usr/sbin:/usr/bin:/sbin:/bin']);
    if (!is_resource($process)) {
        return ['warning', 'Could not run the fixed systemd status check.'];
    }
    $exitCode = proc_close($process);
    return $exitCode === 0
        ? ['ok', 'Service is active.']
        : ['critical', 'Service is not active; inspect systemd status and journal logs.'];
}

function saveHealth(PDO $pdo, int $serverId, string $key, string $status, string $details): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO server_health_checks (server_id, service_key, status, details, checked_at)
         VALUES (:server_id, :service_key, :status, :details, NOW())
         ON DUPLICATE KEY UPDATE status = VALUES(status), details = VALUES(details), checked_at = NOW()'
    );
    $stmt->execute([
        'server_id' => $serverId,
        'service_key' => $key,
        'status' => $status,
        'details' => mb_substr($details, 0, 500),
    ]);
}

try {
    $pdo = Database::connection();
    $serverId = (int)$pdo->query('SELECT id FROM servers WHERE is_local = 1 ORDER BY id ASC LIMIT 1')->fetchColumn();
    if ($serverId < 1) {
        throw new RuntimeException('No local server is registered; run the installer or register the local server first.');
    }

    $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $checks = [
        'nginx' => checkUnit('nginx.service'),
        'mariadb' => checkUnit('mariadb.service'),
        'php-fpm' => checkUnit('php' . $phpVersion . '-fpm.service'),
        'worker-timer' => checkUnit('spanel-worker.timer'),
    ];

    $freeBytes = @disk_free_space('/');
    $totalBytes = @disk_total_space('/');
    if ($freeBytes === false || $totalBytes === false || $totalBytes < 1) {
        $checks['disk-space'] = ['warning', 'Could not determine free disk space.'];
    } else {
        $freePercent = ($freeBytes / $totalBytes) * 100;
        if ($freeBytes < 2 * 1024 * 1024 * 1024 || $freePercent < 10) {
            $checks['disk-space'] = ['warning', sprintf('Low free disk space: %.1f%% (%d MiB) remaining.', $freePercent, (int)floor($freeBytes / 1048576))];
        } else {
            $checks['disk-space'] = ['ok', sprintf('%.1f%% free (%d MiB available).', $freePercent, (int)floor($freeBytes / 1048576))];
        }
    }

    $worst = 'ok';
    $rank = ['ok' => 0, 'warning' => 1, 'critical' => 2];
    foreach ($checks as $key => [$status, $details]) {
        saveHealth($pdo, $serverId, $key, $status, $details);
        printf('[%s] %s: %s\n', strtoupper($status), $key, $details);
        if ($rank[$status] > $rank[$worst]) {
            $worst = $status;
        }
    }

    printf("Health snapshot saved for local server #%d; overall status: %s.\n", $serverId, strtoupper($worst));
    exit($worst === 'critical' ? 1 : 0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Health check failed; results may be stale. Details: ' . $e->getMessage() . "\n");
    exit(1);
}
