<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Security.php';
$config = require dirname(__DIR__) . '/config/config.local.php';
Security::startSession($config);
require_once dirname(__DIR__) . '/app/View.php';
$admin = Auth::requireLogin();
$pdo = Database::connection();
$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify($_POST['_csrf'] ?? null);
    $name = trim((string)($_POST['name'] ?? ''));
    $hostname = strtolower(trim((string)($_POST['hostname'] ?? '')));
    $environment = (string)($_POST['environment'] ?? 'development');
    if ($name === '' || mb_strlen($name) > 120) {
        $error = 'Enter a server name (up to 120 characters).';
    } elseif (strlen($hostname) > 253 || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/D', $hostname)) {
        $error = 'Enter a valid hostname or DNS name.';
    } elseif (!in_array($environment, ['development', 'production'], true)) {
        $error = 'Select a valid environment.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare('INSERT INTO servers (name, hostname, environment, status, is_local) VALUES (:name, :hostname, :environment, :status, 0)');
            $stmt->execute(['name' => $name, 'hostname' => $hostname, 'environment' => $environment, 'status' => 'pending']);
            $serverId = (int)$pdo->lastInsertId();
            $audit = $pdo->prepare("INSERT INTO audit_events (admin_id, event_type, target_type, target_id, details_json) VALUES (:admin_id, 'server.registered', 'server', :target_id, :details)");
            $audit->execute(['admin_id' => (int)$admin['id'], 'target_id' => (string)$serverId, 'details' => json_encode(['hostname' => $hostname, 'environment' => $environment], JSON_THROW_ON_ERROR)]);
            $pdo->commit();
            $success = 'Server record created. This does not connect to or configure the remote machine yet.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
                $error = 'A server with that hostname already exists.';
            } else {
                error_log('SPanel server registration failed: ' . $e->getMessage());
                $error = 'The server could not be saved. Check the panel logs.';
            }
        }
    }
}
$allowedStatuses = ['pending', 'online', 'offline', 'maintenance', 'error'];
$filterStatus = trim((string)($_GET['status'] ?? ''));
if (!in_array($filterStatus, $allowedStatuses, true)) { $filterStatus = ''; }
$filterEnvironment = trim((string)($_GET['environment'] ?? ''));
if (!in_array($filterEnvironment, ['development', 'production'], true)) { $filterEnvironment = ''; }
$search = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($search) > 120) { $search = mb_substr($search, 0, 120); }
$sql = "SELECT s.id, s.name, s.hostname, s.environment, s.status, s.is_local, s.created_at, COUNT(w.id) AS website_count
        FROM servers s LEFT JOIN websites w ON w.server_id = s.id WHERE 1=1";
$params = [];
if ($filterStatus !== '') { $sql .= ' AND s.status = :status'; $params['status'] = $filterStatus; }
if ($filterEnvironment !== '') { $sql .= ' AND s.environment = :environment'; $params['environment'] = $filterEnvironment; }
if ($search !== '') { $sql .= ' AND (s.name LIKE :name_search OR s.hostname LIKE :host_search)'; $params['name_search'] = '%' . $search . '%'; $params['host_search'] = '%' . $search . '%'; }
$sql .= ' GROUP BY s.id, s.name, s.hostname, s.environment, s.status, s.is_local, s.created_at ORDER BY s.is_local DESC, s.name LIMIT 200';
$serverStmt = $pdo->prepare($sql); $serverStmt->execute($params); $servers = $serverStmt->fetchAll();
$totalServers = (int)$pdo->query('SELECT COUNT(*) FROM servers')->fetchColumn();
$pendingServers = (int)$pdo->query("SELECT COUNT(*) FROM servers WHERE status='pending'")->fetchColumn();
$connectedServers = (int)$pdo->query("SELECT COUNT(*) FROM servers WHERE status='online'")->fetchColumn();
renderHeader('Servers', 'servers');
?>
<div class="welcome-row"><div><p class="muted">HOSTING INFRASTRUCTURE</p><h2>Hosting servers</h2><p class="muted">Register the machines that will host your websites.</p></div></div>
<?php if ($error !== ''): ?><div class="alert"><?= Security::escape($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="notice"><strong>Server saved</strong><p><?= Security::escape($success) ?></p></div><?php endif; ?>
<div class="stat-grid"><article class="stat-card"><div class="stat-top"><span>Total servers</span><span class="stat-icon">▦</span></div><strong><?= $totalServers ?></strong><small>Registered nodes</small></article><article class="stat-card"><div class="stat-top"><span>Online</span><span class="stat-icon green">●</span></div><strong><?= $connectedServers ?></strong><small>Marked online; not agent-verified</small></article><article class="stat-card"><div class="stat-top"><span>Pending</span><span class="stat-icon amber">◷</span></div><strong><?= $pendingServers ?></strong><small>Not connected yet</small></article><article class="stat-card"><div class="stat-top"><span>Showing</span><span class="stat-icon purple">⌕</span></div><strong><?= count($servers) ?></strong><small>Maximum 200 results</small></article></div>
<div class="section-heading"><div><h3>Find a server</h3><p class="muted">Search by display name or hostname and narrow by status/environment.</p></div></div>
<div class="login-card"><form method="get" class="form-stack"><label>Search<input name="q" value="<?= Security::escape($search) ?>" maxlength="120" placeholder="server.example.com"></label><label>Status<select name="status"><option value="">All statuses</option><?php foreach ($allowedStatuses as $option): ?><option value="<?= Security::escape($option) ?>" <?= $filterStatus === $option ? 'selected' : '' ?>><?= Security::escape(ucfirst(str_replace('_', ' ', $option))) ?></option><?php endforeach; ?></select></label><label>Environment<select name="environment"><option value="">All environments</option><option value="development" <?= $filterEnvironment === 'development' ? 'selected' : '' ?>>Development</option><option value="production" <?= $filterEnvironment === 'production' ? 'selected' : '' ?>>Production</option></select></label><button class="primary-button" type="submit">Filter servers</button><?php if ($search !== '' || $filterStatus !== '' || $filterEnvironment !== ''): ?><a class="secondary-button link-button" href="/servers.php">Clear filters</a><?php endif; ?></form></div>
<div class="site-table-wrap"><table class="site-table"><thead><tr><th>Server</th><th>Environment</th><th>Status</th><th>Websites</th><th>Type</th></tr></thead><tbody>
<?php foreach ($servers as $server): ?><tr><td><strong><?= Security::escape($server['name']) ?></strong><small><?= Security::escape($server['hostname']) ?></small></td><td><?= Security::escape($server['environment']) ?></td><td><span class="status-badge"><?= Security::escape($server['status']) ?></span></td><td><?= (int)$server['website_count'] ?></td><td><?= (int)$server['is_local'] === 1 ? 'This panel server' : 'Remote (not connected)' ?></td></tr><?php endforeach; ?>
<?php if (!$servers): ?><tr><td colspan="5">No server records yet. Register a server below.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="section-heading"><div><h3>Register a hosting server</h3><p class="muted">This adds inventory metadata only. It does not open network connections.</p></div></div>
<div class="login-card"><form method="post" class="form-stack"><input type="hidden" name="_csrf" value="<?= Security::escape(Csrf::token()) ?>"><label>Display name<input name="name" maxlength="120" placeholder="My Ubuntu VPS" required></label><label>Hostname or DNS name<input name="hostname" maxlength="253" placeholder="server.example.com" required></label><label>Environment<select name="environment"><option value="development">Development</option><option value="production">Production</option></select></label><button class="primary-button" type="submit">Register server</button></form></div>
<div class="notice"><strong>Connection security</strong><p>Remote agents, credentials and server health checks are not enabled yet. Server records remain pending until a secure agent protocol is implemented.</p></div>
<?php renderFooter(); ?>
