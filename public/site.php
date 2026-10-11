<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Security.php';
$config = require dirname(__DIR__) . '/config/config.local.php';
Security::startSession($config);
require_once dirname(__DIR__) . '/app/View.php';
require_once dirname(__DIR__) . '/app/DomainName.php';
require_once dirname(__DIR__) . '/app/PhpMyAdminHandoff.php';
$admin = Auth::requireLogin();
$pdo = Database::connection();
$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id || $id < 1) {
    http_response_code(400);
    exit('A valid website ID is required.');
}
$stmt = $pdo->prepare('SELECT w.*, s.name AS server_name, s.hostname AS server_hostname FROM websites w LEFT JOIN servers s ON s.id = w.server_id WHERE w.id = :id LIMIT 1');
$stmt->execute(['id' => $id]);
$website = $stmt->fetch();
if (!$website) {
    http_response_code(404);
    exit('Website not found.');
}

$error = '';
$success = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify($_POST['_csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_domain') {
        $raw = trim((string)($_POST['domain'] ?? ''));
        $domain = DomainName::normalize($raw);
        $domainType = (string)($_POST['domain_type'] ?? 'alias');
        $validType = in_array($domainType, ['alias', 'subdomain'], true);
        if (!$validType) {
            $error = 'Select whether this domain is an alias or subdomain.';
        } elseif ($domain === null) {
            $error = 'Enter a valid domain name using letters, numbers, dots and hyphens.';
        } elseif ($domain === strtolower((string)$website['domain'])) {
            $error = 'This is already the website primary domain.';
        } else {
            try {
                $pdo->beginTransaction();
                $insert = $pdo->prepare("INSERT INTO website_domains (website_id, domain, domain_type, is_primary, status) VALUES (:website_id, :domain, :domain_type, 0, 'pending')");
                $insert->execute(['website_id' => $id, 'domain' => $domain, 'domain_type' => $domainType]);
                $audit = $pdo->prepare("INSERT INTO audit_events (admin_id, event_type, target_type, target_id, details_json) VALUES (:admin_id, 'website.domain.added', 'website', :target_id, :details)");
                $audit->execute([
                    'admin_id' => (int)$admin['id'],
                    'target_id' => (string)$id,
                    'details' => json_encode(['domain' => $domain, 'domain_type' => $domainType], JSON_THROW_ON_ERROR),
                ]);
                $pdo->commit();
                $success = 'Domain added. DNS and web-server configuration have not been changed yet.';
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
                    $error = 'That domain is already assigned to a website.';
                } else {
                    error_log('SPanel domain insert failed: ' . $e->getMessage());
                    $error = 'The domain could not be saved. Check the panel logs.';
                }
            }
        }
    } elseif ($action === 'open_phpmyadmin') {
        $databaseId = filter_var($_POST['database_id'] ?? null, FILTER_VALIDATE_INT);
        if (!is_int($databaseId) || $databaseId < 1) {
            $error = 'Select a valid website database.';
        } else {
            $databaseStmt = $pdo->prepare(
                'SELECT d.id, d.website_id, d.server_id, d.database_name, d.database_user, d.status, d.secret_ref,
                        w.status AS website_status, w.server_id AS website_server_id, s.is_local
                 FROM website_databases d
                 INNER JOIN websites w ON w.id = d.website_id
                 INNER JOIN servers s ON s.id = d.server_id
                 WHERE d.id = :database_id AND d.website_id = :website_id
                 LIMIT 1'
            );
            $databaseStmt->execute(['database_id' => $databaseId, 'website_id' => $id]);
            $database = $databaseStmt->fetch();
            $expectedName = 'spw_' . $id;
            $expectedUser = 'spu_' . $id;
            if (!$database
                || $website['status'] !== 'active'
                || $database['website_status'] !== 'active'
                || $database['status'] !== 'active'
                || (int)$database['is_local'] !== 1
                || (int)$database['server_id'] !== (int)$database['website_server_id']
                || $database['database_name'] !== $expectedName
                || $database['database_user'] !== $expectedUser
                || $database['secret_ref'] !== 'website-db:' . $id) {
                $error = 'phpMyAdmin is available only for an active database on this local server.';
            } else {
                $remoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
                if (!is_string($remoteAddress) || filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
                    $remoteAddress = null;
                }
                try {
                    $token = PhpMyAdminHandoff::issue($pdo, (int)$admin['id'], $databaseId, $remoteAddress);
                    $_SESSION['pma_pending_handoff'] = [
                        'token' => $token,
                        'database_id' => $databaseId,
                        'website_id' => $id,
                        'issued_at' => time(),
                        'remote_addr' => $remoteAddress,
                    ];
                    header('Location: /phpmyadmin-launch.php', true, 303);
                    exit;
                } catch (Throwable $e) {
                    error_log('SPanel could not issue a phpMyAdmin handoff: ' . $e->getMessage());
                    $error = 'Could not start the secure phpMyAdmin handoff. Check the server setup and try again.';
                }
            }
        }
    } elseif ($action === 'remove_domain') {
        $domain = DomainName::normalize((string)($_POST['domain'] ?? ''));
        if ($domain === null) {
            $error = 'Select a valid domain to remove.';
        } else {
            try {
                $pdo->beginTransaction();
                $remove = $pdo->prepare("DELETE FROM website_domains WHERE website_id = :website_id AND domain = :domain AND is_primary = 0 AND status = 'pending'");
                $remove->execute(['website_id' => $id, 'domain' => $domain]);
                if ($remove->rowCount() !== 1) {
                    $pdo->rollBack();
                    $error = 'Only pending, non-primary domain records can be removed.';
                } else {
                    $audit = $pdo->prepare("INSERT INTO audit_events (admin_id, event_type, target_type, target_id, details_json) VALUES (:admin_id, 'website.domain.removed', 'website', :target_id, :details)");
                    $audit->execute([
                        'admin_id' => (int)$admin['id'],
                        'target_id' => (string)$id,
                        'details' => json_encode(['domain' => $domain], JSON_THROW_ON_ERROR),
                    ]);
                    $pdo->commit();
                    $success = 'Pending domain record removed. No DNS or web-server configuration was changed.';
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                error_log('SPanel domain removal failed: ' . $e->getMessage());
                $error = 'The domain could not be removed. Check the panel logs.';
            }
        }
    } else {
        $error = 'Unknown action.';
    }
}
$domains = $pdo->prepare('SELECT domain, domain_type, is_primary, status, created_at FROM website_domains WHERE website_id = :id ORDER BY is_primary DESC, domain ASC');
$domains->execute(['id' => $id]);
$domains = $domains->fetchAll();
$dbs = $pdo->prepare(
    'SELECT d.id, d.server_id, d.database_name, d.database_user, d.status, d.secret_ref, d.created_at, s.is_local
     FROM website_databases d LEFT JOIN servers s ON s.id = d.server_id
     WHERE d.website_id = :id ORDER BY d.database_name ASC'
);
$dbs->execute(['id' => $id]);
$databases = $dbs->fetchAll();
$operationStmt = $pdo->prepare('SELECT id, operation_type, status, error_code, error_summary, created_at, finished_at FROM operation_jobs WHERE website_id = :id ORDER BY id DESC LIMIT 10');
$operationStmt->execute(['id' => $id]);
$recentOperations = $operationStmt->fetchAll();

renderHeader((string)$website['name'], 'sites');
?>
<div class="welcome-row"><div><p class="muted">WEBSITE MANAGEMENT</p><h2><?= Security::escape($website['domain']) ?></h2><p class="muted">Manage this website's domains and database metadata.</p></div><a class="secondary-button link-button" href="/sites.php">← All websites</a></div>
<?php if ($error !== ''): ?><div class="alert"><?= Security::escape($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="notice"><strong>Saved</strong><p><?= Security::escape($success) ?></p></div><?php endif; ?>
<?php if (($_GET['pma'] ?? '') === 'error'): ?><div class="alert">The secure phpMyAdmin handoff could not be completed. Check that phpMyAdmin and its restricted credential broker are installed, then try again.</div><?php endif; ?>
<div class="stat-grid">
<article class="stat-card"><div class="stat-top"><span>Status</span><span class="stat-icon green">●</span></div><strong class="env-value"><?= Security::escape($website['status']) ?></strong><small><?= $website['status'] === 'active' ? 'Local site health check passed' : 'See recent operation results for setup status' ?></small></article>
<article class="stat-card"><div class="stat-top"><span>PHP version</span><span class="stat-icon">⌘</span></div><strong class="env-value"><?= Security::escape($website['php_version'] ?? 'Not set') ?></strong><small>Selected during provisioning and checked by the helper</small></article>
<article class="stat-card"><div class="stat-top"><span>Hosting server</span><span class="stat-icon purple">▦</span></div><strong class="env-value"><?= Security::escape($website['server_name'] ?? 'Unassigned') ?></strong><small><?= Security::escape($website['server_hostname'] ?? 'No server assigned') ?></small></article>
<article class="stat-card"><div class="stat-top"><span>Databases</span><span class="stat-icon amber">▤</span></div><strong><?= count($databases) ?></strong><small>Metadata records</small></article>
</div>
<div class="section-heading"><div><h3>Recent operations</h3><p class="muted">Latest 10 jobs for this website, including any recovery-required work.</p></div><a class="text-link" href="/operations.php">All operations →</a></div>
<?php if ($recentOperations): ?><div class="site-table-wrap"><table class="site-table"><thead><tr><th>Job</th><th>Operation</th><th>Status</th><th>Created / finished</th><th>Result</th></tr></thead><tbody>
<?php foreach ($recentOperations as $operation): ?><tr><td><strong>#<?= (int)$operation['id'] ?></strong></td><td><?= Security::escape($operation['operation_type']) ?></td><td><span class="status-badge status-<?= Security::escape($operation['status']) ?>"><?= Security::escape(str_replace('_', ' ', $operation['status'])) ?></span></td><td><?= Security::escape($operation['created_at']) ?><small><?= Security::escape($operation['finished_at'] ?? 'Not finished') ?></small></td><td><?php if ($operation['error_code']): ?><strong><?= Security::escape($operation['error_code']) ?></strong><small><?= Security::escape($operation['error_summary'] ?? '') ?></small><?php else: ?><span class="muted">No error recorded</span><?php endif; ?></td></tr><?php endforeach; ?>
</tbody></table></div><?php else: ?><div class="empty-state"><h3>No operations yet</h3><p>Jobs associated with this website will appear here.</p></div><?php endif; ?>
<div class="section-heading"><div><h3>Domains</h3><p class="muted">Domain records are stored here; DNS and Nginx are not changed by this form.</p></div></div>
<div class="site-table-wrap"><table class="site-table"><thead><tr><th>Domain</th><th>Type</th><th>Primary</th><th>Status</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($domains as $domain): ?><tr><td><strong><?= Security::escape($domain['domain']) ?></strong></td><td><?= Security::escape($domain['domain_type']) ?></td><td><?= (int)$domain['is_primary'] === 1 ? 'Yes' : 'No' ?></td><td><span class="status-badge"><?= Security::escape($domain['status']) ?></span></td><td><?php if ((int)$domain['is_primary'] !== 1 && $domain['status'] === 'pending'): ?><form method="post"><input type="hidden" name="_csrf" value="<?= Security::escape(Csrf::token()) ?>"><input type="hidden" name="action" value="remove_domain"><input type="hidden" name="domain" value="<?= Security::escape($domain['domain']) ?>"><button class="secondary-button" type="submit">Remove pending</button></form><?php else: ?><span class="muted">Protected</span><?php endif; ?></td></tr><?php endforeach; ?>
<?php if (!$domains): ?><tr><td colspan="5">No domain relation records yet. Run the migration runner first.</td></tr><?php endif; ?>
</tbody></table></div>
<div class="section-heading"><div><h3>Add domain or subdomain</h3><p class="muted">The domain must not already belong to another website.</p></div></div>
<div class="login-card"><form method="post" class="form-stack"><input type="hidden" name="_csrf" value="<?= Security::escape(Csrf::token()) ?>"><input type="hidden" name="action" value="add_domain"><label>Domain name<input name="domain" placeholder="www.example.com" maxlength="253" required pattern="(?=.{1,253}$)([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(\.([A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*"></label><label>Domain type<select name="domain_type"><option value="alias">Alias</option><option value="subdomain">Subdomain</option></select></label><button class="primary-button" type="submit">Add domain</button></form></div>
<div class="section-heading"><div><h3>Databases</h3><p class="muted">Databases are created with a dedicated local-only user and encrypted root-only credential storage. Direct phpMyAdmin sign-on is still being completed.</p></div></div>
<?php if ($databases): ?><div class="site-table-wrap"><table class="site-table"><thead><tr><th>Database</th><th>Username</th><th>Status</th><th>Created</th><th>Tools</th></tr></thead><tbody><?php foreach ($databases as $db): ?><tr><td><strong><?= Security::escape($db['database_name']) ?></strong></td><td><?= Security::escape($db['database_user']) ?></td><td><span class="status-badge"><?= Security::escape($db['status'] ?? 'active') ?></span></td><td><?= Security::escape($db['created_at']) ?></td><td><?php if ($website['status'] === 'active' && $db['status'] === 'active' && (int)$db['is_local'] === 1 && $db['database_name'] === 'spw_' . $id && $db['database_user'] === 'spu_' . $id && $db['secret_ref'] === 'website-db:' . $id): ?><form method="post" action="/site.php?id=<?= (int)$id ?>" class="inline-form"><input type="hidden" name="_csrf" value="<?= Security::escape(Csrf::token()) ?>"><input type="hidden" name="action" value="open_phpmyadmin"><input type="hidden" name="database_id" value="<?= (int)$db['id'] ?>"><button class="secondary-button" type="submit">Open phpMyAdmin ↗</button></form><?php else: ?><span class="muted">Unavailable until database setup is complete</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div><?php else: ?><div class="empty-state"><div class="empty-icon">▤</div><h3>No databases tracked</h3><p>Choose “Create a website database” in the website wizard, or create one when that management action is added.</p></div><?php endif; ?>
<div class="notice"><strong>Development mode</strong><p>Database access uses phpMyAdmin's supported signon authentication and a short-lived server-side handoff. Keep this VM private; DNS automation and SSL remain disabled until their acceptance checks pass.</p></div>
<?php renderFooter(); ?>
