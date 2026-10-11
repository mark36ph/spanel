<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Security.php';
$config = require dirname(__DIR__) . '/config/config.local.php';
Security::startSession($config);
require_once dirname(__DIR__) . '/app/View.php';
Auth::requireLogin();
$pdo = Database::connection();
$siteCount = (int)$pdo->query('SELECT COUNT(*) FROM websites')->fetchColumn();
$activeCount = (int)$pdo->query("SELECT COUNT(*) FROM websites WHERE status = 'active'")->fetchColumn();
$dbCount = (int)$pdo->query('SELECT COUNT(*) FROM website_databases')->fetchColumn();
$serverCount = (int)$pdo->query('SELECT COUNT(*) FROM servers')->fetchColumn();
$queuedCount = (int)$pdo->query("SELECT COUNT(*) FROM operation_jobs WHERE status = 'queued'")->fetchColumn();
$recoveryCount = (int)$pdo->query("SELECT COUNT(*) FROM operation_jobs WHERE status = 'rollback_required'")->fetchColumn();
$recentJobs = $pdo->query("SELECT j.id, j.operation_type, j.status, j.created_at, w.name AS website_name, w.domain AS website_domain FROM operation_jobs j LEFT JOIN websites w ON w.id = j.website_id ORDER BY j.id DESC LIMIT 6")->fetchAll();
renderHeader('Dashboard', 'dashboard');
?>
<div class="welcome-row"><div><p class="muted">SERVER OVERVIEW</p><h2>Your hosting, one place.</h2><p class="muted">The foundation for Projectabe, FactBurstQuiz and future websites.</p></div><a class="primary-button link-button" href="/sites.php">Manage websites <span>→</span></a></div>
<div class="stat-grid">
<article class="stat-card"><div class="stat-top"><span>Websites</span><span class="stat-icon">◎</span></div><strong><?= $siteCount ?></strong><small>Registered in SPanel</small></article>
<article class="stat-card"><div class="stat-top"><span>Active websites</span><span class="stat-icon green">↗</span></div><strong><?= $activeCount ?></strong><small>Marked active</small></article>
<article class="stat-card"><div class="stat-top"><span>Databases</span><span class="stat-icon purple">▤</span></div><strong><?= $dbCount ?></strong><small>Tracked by SPanel</small></article>
<article class="stat-card"><div class="stat-top"><span>Hosting servers</span><span class="stat-icon purple">▦</span></div><strong><?= $serverCount ?></strong><small>Registered nodes</small></article>
</div>
<div class="section-heading"><div><h3>Websites</h3><p class="muted">Managed sites appear here.</p></div><a class="text-link" href="/sites.php">View all →</a></div>
<?php $sites = $pdo->query('SELECT name, domain, status, php_version, created_at FROM websites ORDER BY name ASC LIMIT 6')->fetchAll(); ?>
<?php if (!$sites): ?><div class="empty-state"><div class="empty-icon">⌘</div><h3>No websites added yet</h3><p>Website creation is the next stage. This dashboard will show your sites after registration.</p><a class="secondary-button link-button" href="/sites.php">Open website inventory</a></div>
<?php else: ?><div class="site-table-wrap"><table class="site-table"><thead><tr><th>Website</th><th>PHP</th><th>Status</th><th>Created</th></tr></thead><tbody>
<?php foreach ($sites as $site): ?><tr><td><strong><?= Security::escape($site['name']) ?></strong><small><?= Security::escape($site['domain']) ?></small></td><td><?= Security::escape($site['php_version'] ?? '—') ?></td><td><span class="status-badge"><?= Security::escape($site['status']) ?></span></td><td><?= Security::escape($site['created_at']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<div class="section-heading"><div><h3>Operation queue</h3><p class="muted">Recent background work and manual recovery status.</p></div><a class="text-link" href="/operations.php">View queue →</a></div>
<div class="stat-grid"><article class="stat-card"><div class="stat-top"><span>Queued jobs</span><span class="stat-icon amber">◷</span></div><strong><?= $queuedCount ?></strong><small>Waiting for a worker</small></article><article class="stat-card"><div class="stat-top"><span>Recovery required</span><span class="stat-icon">!</span></div><strong><?= $recoveryCount ?></strong><small>Manual inspection needed</small></article></div>
<?php if ($recoveryCount > 0): ?><div class="alert">One or more operations need manual recovery. Inspect the server before retrying any work.</div><?php endif; ?>
<?php if ($recentJobs): ?><div class="site-table-wrap"><table class="site-table"><thead><tr><th>Job</th><th>Website</th><th>Status</th><th>Created</th></tr></thead><tbody><?php foreach ($recentJobs as $job): ?><tr><td><strong>#<?= (int)$job['id'] ?> · <?= Security::escape($job['operation_type']) ?></strong></td><td><?= Security::escape($job['website_name'] ?? 'Unassigned') ?><small><?= Security::escape($job['website_domain'] ?? '') ?></small></td><td><span class="status-badge status-<?= Security::escape($job['status']) ?>"><?= Security::escape(str_replace('_', ' ', $job['status'])) ?></span></td><td><?= Security::escape($job['created_at']) ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><h3>No background jobs</h3><p>Website provisioning and maintenance jobs will be listed here.</p></div><?php endif; ?>
<div class="notice"><strong>Build status</strong><p>Stage 1 provides authentication and the interface. Privileged server changes will be added only with validation and rollback.</p></div>
<?php renderFooter(); ?>
