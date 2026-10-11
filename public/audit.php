<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Security.php';
$config = require dirname(__DIR__) . '/config/config.local.php';
Security::startSession($config);
require_once dirname(__DIR__) . '/app/View.php';
Auth::requireLogin();
$pdo = Database::connection();
$eventType = trim((string)($_GET['event_type'] ?? ''));
$targetType = trim((string)($_GET['target_type'] ?? ''));
if (mb_strlen($eventType) > 100 || preg_match('/^[a-zA-Z0-9_.:-]*$/D', $eventType) !== 1) { $eventType = ''; }
if (mb_strlen($targetType) > 100 || preg_match('/^[a-zA-Z0-9_-]*$/D', $targetType) !== 1) { $targetType = ''; }
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);
$page = $page !== false && $page !== null && $page > 0 ? min($page, 100000) : 1;
$perPage = 50; $where = []; $params = [];
if ($eventType !== '') { $where[] = 'a.event_type = :event_type'; $params['event_type'] = $eventType; }
if ($targetType !== '') { $where[] = 'a.target_type = :target_type'; $params['target_type'] = $targetType; }
$whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$countStmt = $pdo->prepare('SELECT COUNT(*) FROM audit_events a' . $whereSql); $countStmt->execute($params);
$total = (int)$countStmt->fetchColumn(); $pages = max(1, (int)ceil($total / $perPage)); $page = min($page, $pages);
$sql = "SELECT a.id, a.event_type, a.target_type, a.target_id, a.details_json, a.remote_addr, a.created_at, ad.email AS admin_email
        FROM audit_events a LEFT JOIN admins ad ON ad.id = a.admin_id" . $whereSql . ' ORDER BY a.id DESC LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage);
$stmt = $pdo->prepare($sql); $stmt->execute($params); $events = $stmt->fetchAll();
$eventTypes = $pdo->query('SELECT DISTINCT event_type FROM audit_events ORDER BY event_type ASC LIMIT 100')->fetchAll(PDO::FETCH_COLUMN);
$targetTypes = $pdo->query('SELECT DISTINCT target_type FROM audit_events WHERE target_type IS NOT NULL ORDER BY target_type ASC LIMIT 30')->fetchAll(PDO::FETCH_COLUMN);
renderHeader('Audit log', 'audit');
?>
<div class="welcome-row"><div><p class="muted">SECURITY & ACCOUNTABILITY</p><h2>Audit log</h2><p class="muted">Read-only history of administrator actions and system operation events.</p></div></div>
<div class="stat-grid"><article class="stat-card"><div class="stat-top"><span>Matching events</span><span class="stat-icon">≡</span></div><strong><?= $total ?></strong><small>Filtered audit records</small></article><article class="stat-card"><div class="stat-top"><span>Current page</span><span class="stat-icon purple">#</span></div><strong><?= $page ?> / <?= $pages ?></strong><small><?= $perPage ?> events per page</small></article></div>
<div class="section-heading"><div><h3>Filter events</h3><p class="muted">Exact matches; filters can be combined.</p></div></div>
<div class="login-card"><form method="get" class="form-stack">
<label>Event type<select name="event_type"><option value="">All event types</option><?php foreach ($eventTypes as $option): ?><option value="<?= Security::escape((string)$option) ?>" <?= $eventType === $option ? 'selected' : '' ?>><?= Security::escape((string)$option) ?></option><?php endforeach; ?></select></label>
<label>Target type<select name="target_type"><option value="">All target types</option><?php foreach ($targetTypes as $option): ?><option value="<?= Security::escape((string)$option) ?>" <?= $targetType === $option ? 'selected' : '' ?>><?= Security::escape((string)$option) ?></option><?php endforeach; ?></select></label>
<button class="primary-button" type="submit">Apply filters</button>
<?php if ($eventType !== '' || $targetType !== ''): ?><a class="secondary-button link-button" href="/audit.php">Clear filters</a><?php endif; ?>
</form></div>
<div class="section-heading"><div><h3>Events</h3><p class="muted">Newest first. Audit records cannot be edited here.</p></div></div>
<?php if (!$events): ?><div class="empty-state"><div class="empty-icon">≡</div><h3>No audit events found</h3><p>Actions recorded by SPanel will appear here.</p></div><?php else: ?>
<div class="site-table-wrap"><table class="site-table"><thead><tr><th>Time / ID</th><th>Actor</th><th>Event</th><th>Target</th><th>Details</th><th>Remote address</th></tr></thead><tbody>
<?php foreach ($events as $event): ?><tr><td><strong>#<?= (int)$event['id'] ?></strong><small><?= Security::escape($event['created_at']) ?></small></td><td><?= Security::escape($event['admin_email'] ?? 'System / deleted admin') ?></td><td><strong><?= Security::escape($event['event_type']) ?></strong></td><td><?= Security::escape($event['target_type'] ?? '—') ?><small><?= Security::escape($event['target_id'] ?? '—') ?></small></td><td><small><?= Security::escape(mb_substr((string)($event['details_json'] ?? ''), 0, 500)) ?></small></td><td><?= Security::escape($event['remote_addr'] ?? '—') ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php endif; ?>
<div class="section-heading"><div><p class="muted">Page <?= $page ?> of <?= $pages ?> · <?= $total ?> matching events</p></div><div><?php if ($page > 1): ?><a class="secondary-button link-button" href="?<?= Security::escape(http_build_query(['event_type' => $eventType, 'target_type' => $targetType, 'page' => $page - 1])) ?>">← Previous</a><?php endif; ?> <?php if ($page < $pages): ?><a class="secondary-button link-button" href="?<?= Security::escape(http_build_query(['event_type' => $eventType, 'target_type' => $targetType, 'page' => $page + 1])) ?>">Next →</a><?php endif; ?></div></div>
<div class="notice"><strong>Audit hygiene</strong><p>Audit details should contain non-secret metadata only. Passwords, tokens, private keys and database credentials must never be written to event details or logs.</p></div>
<?php renderFooter(); ?>
