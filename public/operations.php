<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Security.php';
$config = require dirname(__DIR__) . '/config/config.local.php';
Security::startSession($config);
require_once dirname(__DIR__) . '/app/View.php';
Auth::requireLogin();

$pdo = Database::connection();
$allowedStatuses = ['queued', 'running', 'succeeded', 'failed', 'rollback_required', 'cancelled'];
$statusFilter = trim((string)($_GET['status'] ?? ''));
if ($statusFilter !== '' && !in_array($statusFilter, $allowedStatuses, true)) {
    $statusFilter = '';
}
$sql = "SELECT j.id, j.operation_type, j.status, j.attempt_count, j.created_at, j.started_at,
               j.finished_at, j.error_code, j.error_summary, j.result_json,
               w.name AS website_name, w.domain AS website_domain, s.name AS server_name
        FROM operation_jobs j
        LEFT JOIN websites w ON w.id = j.website_id
        LEFT JOIN servers s ON s.id = j.server_id";
if ($statusFilter !== '') {
    $sql .= ' WHERE j.status = :status';
}
$sql .= ' ORDER BY j.id DESC LIMIT 100';
$stmt = $pdo->prepare($sql);
if ($statusFilter !== '') {
    $stmt->execute(['status' => $statusFilter]);
} else {
    $stmt->execute();
}
$jobs = $stmt->fetchAll();

$rollbackCount = (int)$pdo->query("SELECT COUNT(*) FROM operation_jobs WHERE status = 'rollback_required'")->fetchColumn();
$queuedCount = (int)$pdo->query("SELECT COUNT(*) FROM operation_jobs WHERE status = 'queued'")->fetchColumn();

// Reflect the actual local helper state instead of showing a permanent, stale warning.
$helperPath = '/usr/local/sbin/spanel-ops';
clearstatcache(true, $helperPath);
$helperStat = is_link($helperPath) ? false : @lstat($helperPath);
$helperReady = is_array($helperStat)
    && is_file($helperPath)
    && is_executable($helperPath)
    && (int)($helperStat['uid'] ?? -1) === 0
    && (((int)($helperStat['mode'] ?? 0)) & 0022) === 0;

renderHeader('Operations', 'operations');
?>
<div class="welcome-row"><div><p class="muted">BACKGROUND TASKS</p><h2>Operations queue</h2><p class="muted">Inspect queued work, completed results and recovery-required jobs.</p></div></div>
<div class="stat-grid">
<article class="stat-card"><div class="stat-top"><span>Queued</span><span class="stat-icon amber">◷</span></div><strong><?= $queuedCount ?></strong><small>Waiting for a worker run</small></article>
<article class="stat-card"><div class="stat-top"><span>Recovery required</span><span class="stat-icon">!</span></div><strong><?= $rollbackCount ?></strong><small>Inspect before retrying</small></article>
</div>
<?php if ($rollbackCount > 0): ?><div class="alert"><strong>Manual recovery required.</strong> One or more jobs may have changed server resources without a confirmed metadata commit. Inspect the affected server before retrying or deleting anything.</div><?php endif; ?>
<?php if ($helperReady): ?>
<div class="notice"><strong>Restricted operations helper detected</strong><p>The helper exists with root ownership and safe file permissions. This check does not prove provisioning will succeed; review job results and server state before retrying a failed operation.</p></div>
<?php else: ?>
<div class="notice"><strong>Provisioning unavailable</strong><p>The root-owned restricted operations helper is missing or fails its ownership/permission checks. Provisioning remains disabled until the helper is installed securely using the installer on a clean VM.</p></div>
<?php endif; ?>
<div class="notice"><strong>Interrupted-job recovery</strong><p>Before changing a stale job, inspect the server. The CLI recovery tool is read-only by default:</p><p><code>php bin/reconcile-operations.php</code></p><p>Only after reviewing the listed IDs, explicitly mark stale jobs for manual recovery with <code>php bin/reconcile-operations.php --minutes=15 --apply</code>. It never retries jobs or deletes server resources.</p></div>
<div class="section-heading"><div><h3>Recent jobs</h3><p class="muted">Showing up to 100 jobs<?= $statusFilter !== '' ? ' with status ' . Security::escape($statusFilter) : '' ?>.</p></div></div>
<div class="login-card"><form method="get" class="form-stack"><label>Filter by status<select name="status"><option value="">All statuses</option><?php foreach ($allowedStatuses as $status): ?><option value="<?= Security::escape($status) ?>" <?= $statusFilter === $status ? 'selected' : '' ?>><?= Security::escape(str_replace('_', ' ', ucfirst($status))) ?></option><?php endforeach; ?></select></label><button class="primary-button" type="submit">Apply filter</button><?php if ($statusFilter !== ''): ?><a class="secondary-button link-button" href="/operations.php">Clear filter</a><?php endif; ?></form></div>
<div class="site-table-wrap"><table class="site-table"><thead><tr><th>Job</th><th>Website / server</th><th>Status</th><th>Attempts</th><th>Timeline</th><th>Result / details</th></tr></thead><tbody>
<?php foreach ($jobs as $job): ?>
<tr>
<td><strong>#<?= (int)$job['id'] ?> · <?= Security::escape($job['operation_type']) ?></strong></td>
<td><?= Security::escape($job['website_name'] ?? '—') ?><small><?= Security::escape($job['website_domain'] ?? '') ?></small><small><?= Security::escape($job['server_name'] ?? 'No server') ?></small></td>
<td><span class="status-badge status-<?= Security::escape($job['status']) ?>"><?= Security::escape(str_replace('_', ' ', $job['status'])) ?></span></td>
<td><?= (int)$job['attempt_count'] ?></td>
<td><small>Created: <?= Security::escape($job['created_at'] ?? '—') ?></small><small>Started: <?= Security::escape($job['started_at'] ?? '—') ?></small><small>Finished: <?= Security::escape($job['finished_at'] ?? '—') ?></small></td>
<td><?php if ($job['error_code']): ?><strong><?= Security::escape($job['error_code']) ?></strong><small><?= Security::escape($job['error_summary'] ?? '') ?></small><?php elseif ($job['result_json']): ?><small><?= Security::escape(mb_substr((string)$job['result_json'], 0, 500)) ?></small><?php else: ?><span class="muted">No result recorded</span><?php endif; ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$jobs): ?><tr><td colspan="6">No operations match this filter.</td></tr><?php endif; ?>
</tbody></table></div>
<?php renderFooter(); ?>
