<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Security.php';
$config = require dirname(__DIR__) . '/config/config.local.php';
Security::startSession($config);
require_once dirname(__DIR__) . '/app/View.php';
Auth::requireLogin();

$pdo = Database::connection();
$server = $pdo->query("SELECT id, name, hostname FROM servers WHERE is_local = 1 ORDER BY id ASC LIMIT 1")->fetch();
$checks = [];
if ($server) {
    $stmt = $pdo->prepare(
        'SELECT service_key, status, details, checked_at
         FROM server_health_checks WHERE server_id = :server_id ORDER BY service_key ASC'
    );
    $stmt->execute(['server_id' => (int)$server['id']]);
    $checks = $stmt->fetchAll();
}
$labels = [
    'nginx' => 'Nginx web server',
    'php-fpm' => 'PHP-FPM',
    'mariadb' => 'MariaDB database',
    'worker-timer' => 'Provisioning worker timer',
    'disk-space' => 'Disk space',
];
if ($checks) {
    $indexedChecks = [];
    foreach ($checks as $check) {
        $indexedChecks[(string)$check['service_key']] = $check;
    }
    $checks = [];
    foreach ($labels as $key => $_label) {
        $checks[] = $indexedChecks[$key] ?? [
            'service_key' => $key,
            'status' => 'unknown',
            'details' => 'No health result has been recorded for this component yet.',
            'checked_at' => 'Never',
        ];
    }
}
$hasCritical = false;
$hasWarning = false;
$now = time();
foreach ($checks as &$check) {
    $checkedAt = strtotime((string)$check['checked_at']);
    $check['stale'] = $checkedAt === false || $checkedAt < ($now - 600);
    if ($check['stale'] && $check['status'] === 'ok') {
        $check['display_status'] = 'unknown';
    } else {
        $check['display_status'] = (string)$check['status'];
    }
    if (in_array($check['display_status'], ['critical'], true)) {
        $hasCritical = true;
    } elseif (in_array($check['display_status'], ['warning', 'unknown'], true)) {
        $hasWarning = true;
    }
}
unset($check);
renderHeader('Server health', 'health');
?>
<div class="welcome-row"><div><p class="muted">SYSTEM MONITORING</p><h2>Local server health</h2><p class="muted">Read-only checks for core services and available disk space.</p></div></div>
<?php if (!$server): ?>
<div class="alert">No local server is registered. Run the installer or register the local server before collecting health data.</div>
<?php elseif (!$checks): ?>
<div class="notice"><strong>Health checks are ready but no snapshot exists yet.</strong><p>On the VM, run <code>sudo -u spanel php /opt/spanel/bin/health-check.php</code>. The scheduled check will then refresh results every five minutes.</p></div>
<?php else: ?>
<div class="stat-grid">
<article class="stat-card"><div class="stat-top"><span>Health status</span><span class="stat-icon <?= $hasCritical ? 'amber' : ($hasWarning ? 'purple' : 'green') ?>">●</span></div><strong class="env-value"><?= $hasCritical ? 'Attention' : ($hasWarning ? 'Check data' : 'Healthy') ?></strong><small><?= $hasCritical ? 'At least one critical check failed' : ($hasWarning ? 'A warning or stale result needs review' : 'Latest checks report no problems') ?></small></article>
<article class="stat-card"><div class="stat-top"><span>Checks recorded</span><span class="stat-icon">✓</span></div><strong><?= count($checks) ?></strong><small>Latest snapshot for <?= Security::escape($server['name']) ?></small></article>
</div>
<div class="site-table-wrap"><table class="site-table"><thead><tr><th>Component</th><th>Status</th><th>Details</th><th>Last checked</th></tr></thead><tbody>
<?php foreach ($checks as $check): ?>
<tr>
<td><strong><?= Security::escape($labels[$check['service_key']] ?? $check['service_key']) ?></strong></td>
<td><span class="status-badge status-<?= Security::escape($check['display_status']) ?>"><?= Security::escape(ucfirst($check['display_status'])) ?></span></td>
<td><?= Security::escape($check['stale'] ? 'Result is over 10 minutes old. ' . $check['details'] : $check['details']) ?></td>
<td><?= Security::escape($check['checked_at']) ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<div class="notice"><strong>Scope and limitations</strong><p>These checks run locally as the unprivileged SPanel account and do not execute browser-supplied commands. They check service activity and disk space, not external website uptime, DNS, SSL, remote servers, application correctness or security. A missing or stale snapshot is not treated as healthy.</p><p>Manual check: <code>sudo -u spanel php /opt/spanel/bin/health-check.php</code></p></div>
<?php renderFooter(); ?>
