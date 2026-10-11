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
renderHeader('Dashboard', 'dashboard');
?>
<div class="welcome-row"><div><p class="muted">SERVER OVERVIEW</p><h2>Your hosting, one place.</h2><p class="muted">The foundation for Projectabe, FactBurstQuiz and future websites.</p></div><a class="primary-button link-button" href="/sites.php">Manage websites <span>→</span></a></div>
<div class="stat-grid">
<article class="stat-card"><div class="stat-top"><span>Websites</span><span class="stat-icon">◎</span></div><strong><?= $siteCount ?></strong><small>Registered in SPanel</small></article>
<article class="stat-card"><div class="stat-top"><span>Active websites</span><span class="stat-icon green">↗</span></div><strong><?= $activeCount ?></strong><small>Marked active</small></article>
<article class="stat-card"><div class="stat-top"><span>Databases</span><span class="stat-icon purple">▤</span></div><strong><?= $dbCount ?></strong><small>Tracked by SPanel</small></article>
<article class="stat-card"><div class="stat-top"><span>Environment</span><span class="stat-icon amber">⌘</span></div><strong class="env-value">Development</strong><small>Local VM — not production</small></article>
</div>
<div class="section-heading"><div><h3>Websites</h3><p class="muted">Managed sites appear here.</p></div><a class="text-link" href="/sites.php">View all →</a></div>
<?php $sites = $pdo->query('SELECT name, domain, status, php_version, created_at FROM websites ORDER BY name ASC LIMIT 6')->fetchAll(); ?>
<?php if (!$sites): ?><div class="empty-state"><div class="empty-icon">⌘</div><h3>No websites added yet</h3><p>Website creation is the next stage. This dashboard will show your sites after registration.</p><a class="secondary-button link-button" href="/sites.php">Open website inventory</a></div>
<?php else: ?><div class="site-table-wrap"><table class="site-table"><thead><tr><th>Website</th><th>PHP</th><th>Status</th><th>Created</th></tr></thead><tbody>
<?php foreach ($sites as $site): ?><tr><td><strong><?= Security::escape($site['name']) ?></strong><small><?= Security::escape($site['domain']) ?></small></td><td><?= Security::escape($site['php_version'] ?? '—') ?></td><td><span class="status-badge"><?= Security::escape($site['status']) ?></span></td><td><?= Security::escape($site['created_at']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<div class="notice"><strong>Build status</strong><p>Stage 1 provides authentication and the interface. Privileged server changes will be added only with validation and rollback.</p></div>
<?php renderFooter(); ?>
