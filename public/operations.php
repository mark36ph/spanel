<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Security.php';
$config = require dirname(__DIR__) . '/config/config.local.php';
Security::startSession($config);
require_once dirname(__DIR__) . '/app/View.php';
Auth::requireLogin();
$jobs = Database::connection()->query("SELECT j.id, j.operation_type, j.status, j.attempt_count, j.created_at, j.started_at, j.finished_at, j.error_code, j.error_summary, w.name AS website_name, s.name AS server_name FROM operation_jobs j LEFT JOIN websites w ON w.id = j.website_id LEFT JOIN servers s ON s.id = j.server_id ORDER BY j.id DESC LIMIT 100")->fetchAll();
renderHeader('Operations', 'operations');
?>
<div class="welcome-row"><div><p class="muted">BACKGROUND TASKS</p><h2>Operations queue</h2><p class="muted">Provisioning and maintenance work is recorded here for audit and recovery.</p></div></div>
<div class="notice"><strong>Worker not enabled yet</strong><p>Jobs can be queued and inspected, but no privileged worker runs them yet. This deliberately prevents a web request from executing server commands.</p></div>
<div class="section-heading"><div><h3>Recent jobs</h3><p class="muted">Showing up to 100 latest operations.</p></div></div>
<div class="site-table-wrap"><table class="site-table"><thead><tr><th>Job</th><th>Website / server</th><th>Status</th><th>Attempts</th><th>Created</th><th>Details</th></tr></thead><tbody>
<?php foreach ($jobs as $job): ?><tr><td><strong>#<?= (int)$job['id'] ?> · <?= Security::escape($job['operation_type']) ?></strong></td><td><?= Security::escape($job['website_name'] ?? '—') ?><small><?= Security::escape($job['server_name'] ?? 'No server') ?></small></td><td><span class="status-badge"><?= Security::escape($job['status']) ?></span></td><td><?= (int)$job['attempt_count'] ?></td><td><?= Security::escape($job['created_at']) ?></td><td><?php if ($job['error_code']): ?><strong><?= Security::escape($job['error_code']) ?></strong><small><?= Security::escape($job['error_summary'] ?? '') ?></small><?php else: ?><span class="muted">No errors recorded</span><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$jobs): ?><tr><td colspan="6">No operations have been queued.</td></tr><?php endif; ?>
</tbody></table></div>
<?php renderFooter(); ?>
