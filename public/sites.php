<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Security.php';
$config = require dirname(__DIR__) . '/config/config.local.php';
Security::startSession($config);
require_once dirname(__DIR__) . '/app/View.php';
Auth::requireLogin();
renderHeader('Websites', 'sites');
$sites = Database::connection()->query('SELECT id, name, domain, status, php_version, created_at FROM websites ORDER BY name ASC')->fetchAll();
?>
<div class="welcome-row"><div><p class="muted">WEBSITE INVENTORY</p><h2>All websites</h2><p class="muted">Each site will have its own sidebar and settings area.</p></div><button class="primary-button" disabled title="Available in Stage 2">＋ Create website <span>→</span></button></div>
<?php if (!$sites): ?><div class="empty-state"><div class="empty-icon">◎</div><h3>Your website list is empty</h3><p>Stage 2 will validate a domain, prepare its document root and generate a reviewed Nginx configuration.</p><div class="feature-chips"><span>Domain</span><span>PHP version</span><span>Document root</span><span>SSL</span></div></div>
<?php else: ?><div class="site-table-wrap"><table class="site-table"><thead><tr><th>Website</th><th>PHP</th><th>Status</th><th>Created</th></tr></thead><tbody>
<?php foreach ($sites as $site): ?><tr><td><strong><a class="text-link" href="/site.php?id=<?= (int)$site['id'] ?>"><?= Security::escape($site['name']) ?></a></strong><small><?= Security::escape($site['domain']) ?></small></td><td><?= Security::escape($site['php_version'] ?? '—') ?></td><td><span class="status-badge"><?= Security::escape($site['status']) ?></span></td><td><?= Security::escape($site['created_at']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<?php renderFooter(); ?>
