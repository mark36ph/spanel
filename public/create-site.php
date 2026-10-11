<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Security.php';
$config = require dirname(__DIR__) . '/config/config.local.php';
Security::startSession($config);
require_once dirname(__DIR__) . '/app/View.php';
require_once dirname(__DIR__) . '/app/OperationQueue.php';
require_once dirname(__DIR__) . '/app/DomainName.php';
$admin = Auth::requireLogin();
$pdo = Database::connection();
$servers = $pdo->query("SELECT id, name, hostname, status, is_local FROM servers WHERE is_local = 1 ORDER BY name")->fetchAll();
$error = '';
$createdId = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify($_POST['_csrf'] ?? null);
    $name = trim((string)($_POST['name'] ?? ''));
    $domain = DomainName::normalize((string)($_POST['domain'] ?? ''));
    $serverId = filter_var($_POST['server_id'] ?? null, FILTER_VALIDATE_INT);
    $phpVersion = trim((string)($_POST['php_version'] ?? ''));
    $createDatabase = ($_POST['create_database'] ?? '') === '1';

    if ($name === '' || mb_strlen($name) > 120) {
        $error = 'Enter a website name (up to 120 characters).';
    } elseif ($domain === null) {
        $error = 'Enter a valid domain name.';
    } elseif (!$serverId || !in_array((int)$serverId, array_map(static fn(array $s): int => (int)$s['id'], $servers), true)) {
        $error = 'Select a registered hosting server.';
    } elseif (!in_array($phpVersion, ['', '8.2', '8.3'], true)) {
        $error = 'Select a supported planned PHP version.';
    } else {
        $existing = $pdo->prepare('SELECT id FROM websites WHERE domain = :domain LIMIT 1');
        $existing->execute(['domain' => $domain]);
        if ($existing->fetchColumn() !== false) {
            $error = 'That primary domain is already registered.';
        } else {
            try {
                $pdo->beginTransaction();
                $stmt = $pdo->prepare("INSERT INTO websites (server_id, name, domain, document_root, php_version, status) VALUES (:server_id, :name, :domain, :document_root, :php_version, 'pending')");
                $stmt->execute([
                    'server_id' => (int)$serverId,
                    'name' => $name,
                    'domain' => $domain,
                    'document_root' => '/var/www/pending/' . bin2hex(random_bytes(8)) . '/public',
                    'php_version' => $phpVersion === '' ? null : $phpVersion,
                ]);
                $createdId = (int)$pdo->lastInsertId();
                $domainStmt = $pdo->prepare("INSERT INTO website_domains (website_id, domain, domain_type, is_primary, status) VALUES (:website_id, :domain, 'primary', 1, 'pending')");
                $domainStmt->execute(['website_id' => $createdId, 'domain' => $domain]);
                OperationQueue::enqueueWebsiteProvision(
                    $pdo,
                    (int)$serverId,
                    $createdId,
                    (int)$admin['id'],
                    $createDatabase,
                    $phpVersion === '' ? null : $phpVersion
                );
                $audit = $pdo->prepare("INSERT INTO audit_events (admin_id, event_type, target_type, target_id, details_json) VALUES (:admin_id, 'website.created_pending', 'website', :target_id, :details)");
                $audit->execute(['admin_id' => (int)$admin['id'], 'target_id' => (string)$createdId, 'details' => json_encode(['domain' => $domain, 'server_id' => (int)$serverId, 'database_requested' => $createDatabase], JSON_THROW_ON_ERROR)]);
                $pdo->commit();
                header('Location: /site.php?id=' . $createdId . '&created=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
                    $error = 'That domain or website is already registered.';
                } else {
                    error_log('SPanel website registration failed: ' . $e->getMessage());
                    $error = 'The website could not be registered. Check the panel logs.';
                }
            }
        }
    }
}
renderHeader('Create website', 'sites');
?>
<div class="welcome-row"><div><p class="muted">WEBSITE SETUP</p><h2>Register a website</h2><p class="muted">Prepare the website and queue provisioning for the operations service.</p></div><a class="secondary-button link-button" href="/sites.php">← All websites</a></div>
<?php if ($error !== ''): ?><div class="alert"><?= Security::escape($error) ?></div><?php endif; ?>
<?php if (!$servers): ?><div class="empty-state"><div class="empty-icon">▦</div><h3>Local hosting server not configured</h3><p>The installer must register this machine as the local hosting server. Remote server records cannot provision websites until secure remote agents are implemented.</p><a class="secondary-button link-button" href="/servers.php">View servers →</a></div>
<?php else: ?>
<div class="login-card"><form method="post" class="form-stack">
<input type="hidden" name="_csrf" value="<?= Security::escape(Csrf::token()) ?>">
<label>Website display name<input name="name" maxlength="120" placeholder="My website" required></label>
<label>Primary domain<input name="domain" maxlength="253" placeholder="example.com" required pattern="(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*"></label>
<label>Hosting server<select name="server_id" required><?php foreach ($servers as $server): ?><option value="<?= (int)$server['id'] ?>"><?= Security::escape($server['name'] . ' — ' . $server['hostname'] . ($server['is_local'] ? ' (panel server)' : ' (' . $server['status'] . ')')) ?></option><?php endforeach; ?></select></label>
<label>PHP version preference<select name="php_version"><option value="">Choose during provisioning</option><option value="8.3">PHP 8.3 (verify installed before provisioning)</option><option value="8.2">PHP 8.2 (verify installed before provisioning)</option></select></label>
<label class="check-label"><input type="checkbox" name="create_database" value="1"> Create a website database <span class="muted">(dedicated user and encrypted server-side credential storage)</span></label>
<button class="primary-button" type="submit">Register and queue setup →</button>
</form></div>
<?php endif; ?>
<div class="notice"><strong>Provisioning safety</strong><p>This form records website/domain metadata and queues a job. The installer builds and installs the restricted helper for the local server. Website setup creates an isolated Linux user, document root with a test landing page, PHP-FPM pool and Nginx site, then checks the local HTTP response. Database creation is optional and uses a dedicated database/user with encrypted root-only credential storage. DNS, SSL and direct phpMyAdmin sign-on are not yet enabled.</p></div>
<?php renderFooter(); ?>
