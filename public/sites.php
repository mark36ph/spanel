<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Security.php';
$config = require dirname(__DIR__) . '/config/config.local.php';
Security::startSession($config);
require_once dirname(__DIR__) . '/app/View.php';
Auth::requireLogin();
$pdo = Database::connection();
$allowedStatuses = ['pending', 'active', 'disabled', 'error'];
$status = trim((string)($_GET['status'] ?? ''));
if (!in_array($status, $allowedStatuses, true)) { $status = ''; }
$search = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($search) > 120) { $search = mb_substr($search, 0, 120); }
$sql = "SELECT w.id, w.name, w.domain, w.status, w.php_version, w.created_at, s.name AS server_name,
        (SELECT j.status FROM operation_jobs j WHERE j.website_id = w.id ORDER BY j.id DESC LIMIT 1) AS latest_job_status
        FROM websites w LEFT JOIN servers s ON s.id = w.server_id WHERE 1=1";
$params = [];
if ($status !== '') { $sql .= ' AND w.status = :status'; $params['status'] = $status; }
if ($search !== '') {
    $sql .= ' AND (w.name LIKE :search_name OR w.domain LIKE :search_domain OR CAST(w.id AS CHAR) = :search_id)';
    $needle = '%' . $search . '%';
    $params['search_name'] = $needle; $params['search_domain'] = $needle; $params['search_id'] = $search;
}
$sql .= ' ORDER BY w.name ASC LIMIT 200';
$stmt = $pdo->prepare($sql); $stmt->execute($params); $sites = $stmt->fetchAll();
$totalCount = (int)$pdo->query('SELECT COUNT(*) FROM websites')->fetchColumn();
$pendingCount = (int)$pdo->query("SELECT COUNT(*) FROM websites WHERE status='pending'")->fetchColumn();
$errorCount = (int)$pdo->query("SELECT COUNT(*) FROM websites WHERE status='error'")->fetchColumn();
renderHeader('Websites', 'sites');
?>
<div class="welcome-row"><div><p class="muted">WEBSITE INVENTORY</p><h2>All websites</h2><p class="muted">Search and filter registered sites. Server changes remain disabled until the restricted operations service is ready.</p></div><a class="primary-button link-button" href="/create-site.php">＋ Create website <span>→</span></a></div>
<div class="stat-grid">
<article class="stat-card"><div class="stat-top"><span>Total websites</span><span class="stat-icon">◎</span></div><strong><?= $totalCount ?></strong><small>Registered in SPanel</small></article>
<article class="stat-card"><div class="stat-top"><span>Pending</span><span class="stat-icon amber">◷</span></div><strong><?= $pendingCount ?></strong><small>Awaiting provisioning or review</small></article>
<article class="stat-card"><div class="stat-top"><span>Errors</span><span class="stat-icon">!</span></div><strong><?= $errorCount ?></strong><small>Require administrator review</small></article>
<article class="stat-card"><div class="stat-top"><span>Showing</span><span class="stat-icon purple">⌕</span></div><strong><?= count($sites) ?></strong><small>Maximum 200 results per view</small></article>
</div>
<div class="section-heading"><div><h3>Find a website</h3><p class="muted">Search by name, domain or numeric website ID.</p></div></div>
<div class="login-card"><form method="get" class="form-stack">
<label>Search<input name="q" value="<?= Security::escape($search) ?>" maxlength="120" placeholder="example.com or website name"></label>
<label>Status<select name="status"><option value="">All statuses</option><?php foreach ($allowedStatuses as $option): ?><option value="<?= Security::escape($option) ?>" <?= $status === $option ? 'selected' : '' ?>><?= Security::escape(ucfirst($option)) ?></option><?php endforeach; ?></select></label>
<button class="primary-button" type="submit">Search websites</button>
<?php if ($search !== '' || $status !== ''): ?><a class="secondary-button link-button" href="/sites.php">Clear filters</a><?php endif; ?>
</form></div>
<div class="section-heading"><div><h3>Results</h3><p class="muted"><?= count($sites) ?> website(s)<?= $status !== '' ? ' · status: ' . Security::escape($status) : '' ?><?= $search !== '' ? ' · search: ' . Security::escape($search) : '' ?></p></div></div>
<?php if (!$sites): ?><div class="empty-state"><div class="empty-icon">◎</div><h3><?= $totalCount === 0 ? 'Your website list is empty' : 'No matching websites' ?></h3><p><?= $totalCount === 0 ? 'Register a website to create its metadata record and queue a provisioning job.' : 'Try another search term or clear the current filters.' ?></p><?php if ($totalCount === 0): ?><a class="secondary-button link-button" href="/create-site.php">Create first website</a><?php endif; ?></div>
<?php else: ?><div class="site-table-wrap"><table class="site-table"><thead><tr><th>Website</th><th>Server</th><th>PHP</th><th>Status</th><th>Latest operation</th><th>Created</th></tr></thead><tbody>
<?php foreach ($sites as $site): ?><tr><td><strong><a class="text-link" href="/site.php?id=<?= (int)$site['id'] ?>"><?= Security::escape($site['name']) ?></a></strong><small><?= Security::escape($site['domain']) ?> · ID <?= (int)$site['id'] ?></small></td><td><?= Security::escape($site['server_name'] ?? 'Unassigned') ?></td><td><?= Security::escape($site['php_version'] ?? 'Auto') ?></td><td><span class="status-badge"><?= Security::escape($site['status']) ?></span></td><td><?php if ($site['latest_job_status']): ?><span class="status-badge status-<?= Security::escape($site['latest_job_status']) ?>"><?= Security::escape(str_replace('_', ' ', $site['latest_job_status'])) ?></span><?php else: ?><span class="muted">No jobs</span><?php endif; ?></td><td><?= Security::escape($site['created_at']) ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<div class="notice"><strong>Provisioning status</strong><p>The inventory shows panel metadata and job status only. A queued job is not proof that a live website, database or server configuration has been created.</p></div>
<?php renderFooter(); ?>
