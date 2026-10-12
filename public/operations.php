<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Security.php';
$config = require dirname(__DIR__) . '/config/config.local.php';
Security::startSession($config);
require_once dirname(__DIR__) . '/app/View.php';
$admin = Auth::requireLogin();

$pdo = Database::connection();

$actionMessage = '';
$actionError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify($_POST['_csrf'] ?? null);
    $action = (string)($_POST['action'] ?? '');
    $jobId = filter_var($_POST['job_id'] ?? null, FILTER_VALIDATE_INT);
    $confirmDomain = trim((string)($_POST['confirm_domain'] ?? ''));
    $confirmResources = (string)($_POST['confirm_resources'] ?? '') === '1';

    if (!$jobId || $jobId < 1 || !$confirmResources) {
        $actionError = 'Confirm the domain and that you inspected the server resources before continuing.';
    } else {
        try {
            $pdo->beginTransaction();
            $stmt = $pdo->prepare(
                "SELECT j.id, j.website_id, j.status AS job_status, j.operation_type,
                        w.domain, w.status AS website_status, w.server_id
                 FROM operation_jobs j
                 INNER JOIN websites w ON w.id = j.website_id
                 WHERE j.id = :id FOR UPDATE"
            );
            $stmt->execute(['id' => $jobId]);
            $target = $stmt->fetch();
            if (!$target || $target['operation_type'] !== 'website.provision'
                || !in_array($target['job_status'], ['failed', 'rollback_required'], true)
                || !in_array($target['website_status'], ['pending', 'error'], true)
                || !hash_equals((string)$target['domain'], $confirmDomain)) {
                throw new RuntimeException('This job is not eligible for recovery or removal, or the domain confirmation did not match.');
            }

            $activeStmt = $pdo->prepare("SELECT COUNT(*) FROM operation_jobs WHERE website_id = :website_id AND status IN ('queued','running','succeeded')");
            $activeStmt->execute(['website_id' => (int)$target['website_id']]);
            if ((int)$activeStmt->fetchColumn() > 0) {
                throw new RuntimeException('This website has another queued, running or successful operation and cannot be changed here.');
            }

            if ($action === 'retry') {
                // The restricted helper still performs strict resource-collision checks.
                // This action is only available after an administrator has inspected
                // the host and explicitly confirmed the cleanup state.
                $update = $pdo->prepare(
                    "UPDATE operation_jobs SET status = 'queued', error_code = NULL, error_summary = NULL,
                     result_json = NULL, started_at = NULL, finished_at = NULL, updated_at = NOW()
                     WHERE id = :id AND status IN ('failed','rollback_required')"
                );
                $update->execute(['id' => $jobId]);
                if ($update->rowCount() !== 1) {
                    throw new RuntimeException('The job changed state; refresh the page and try again.');
                }
                $audit = $pdo->prepare("INSERT INTO audit_events (admin_id, event_type, target_type, target_id, details_json) VALUES (:admin_id, 'website.provision.retry_requested', 'operation_job', :target_id, :details)");
                $audit->execute([
                    'admin_id' => (int)$admin['id'],
                    'target_id' => (string)$jobId,
                    'details' => json_encode(['website_id' => (int)$target['website_id'], 'domain' => $target['domain']], JSON_THROW_ON_ERROR),
                ]);
                $actionMessage = 'Retry queued. The worker will run it shortly.';
            } elseif ($action === 'delete') {
                $dbStmt = $pdo->prepare('SELECT COUNT(*) FROM website_databases WHERE website_id = :website_id');
                $dbStmt->execute(['website_id' => (int)$target['website_id']]);
                $domainStmt = $pdo->prepare('SELECT COUNT(*) FROM website_domains WHERE website_id = :website_id AND is_primary = 0');
                $domainStmt->execute(['website_id' => (int)$target['website_id']]);
                if ((int)$dbStmt->fetchColumn() !== 0 || (int)$domainStmt->fetchColumn() !== 0) {
                    throw new RuntimeException('This website has database or additional-domain records. Use the dedicated recovery workflow instead.');
                }
                $audit = $pdo->prepare("INSERT INTO audit_events (admin_id, event_type, target_type, target_id, details_json) VALUES (:admin_id, 'website.failed_creation.deleted', 'website', :target_id, :details)");
                $audit->execute([
                    'admin_id' => (int)$admin['id'],
                    'target_id' => (string)$target['website_id'],
                    'details' => json_encode(['domain' => $target['domain'], 'job_id' => $jobId, 'resources_confirmed_clean' => true], JSON_THROW_ON_ERROR),
                ]);
                $pdo->prepare('DELETE FROM operation_jobs WHERE website_id = :website_id')->execute(['website_id' => (int)$target['website_id']]);
                $pdo->prepare('DELETE FROM website_domains WHERE website_id = :website_id')->execute(['website_id' => (int)$target['website_id']]);
                $pdo->prepare('DELETE FROM websites WHERE id = :website_id')->execute(['website_id' => (int)$target['website_id']]);
                $actionMessage = 'Failed website record and its failed job history were deleted.';
            } else {
                throw new RuntimeException('Unsupported operation action.');
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $actionError = $e instanceof RuntimeException ? $e->getMessage() : 'The action failed. Check the panel logs.';
            if (!$e instanceof RuntimeException) {
                error_log('SPanel operation recovery action failed: ' . $e->getMessage());
            }
        }
    }
}
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
<?php if ($actionMessage !== ''): ?><div class="notice"><strong><?= Security::escape($actionMessage) ?></strong></div><?php endif; ?>
<?php if ($actionError !== ''): ?><div class="alert"><?= Security::escape($actionError) ?></div><?php endif; ?>
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
<td><?php if ($job['error_code']): ?><strong><?= Security::escape($job['error_code']) ?></strong><small><?= Security::escape($job['error_summary'] ?? '') ?></small><?php elseif ($job['result_json']): ?><small><?= Security::escape(mb_substr((string)$job['result_json'], 0, 500)) ?></small><?php else: ?><span class="muted">No result recorded</span><?php endif; ?>
<?php if ($job['operation_type'] === 'website.provision' && in_array($job['status'], ['failed', 'rollback_required'], true)): ?>
<details><summary>Recover / remove</summary>
<p class="muted">Only proceed after checking the server for leftover site files, users, Nginx/PHP-FPM configuration and databases.</p>
<form method="post" class="form-stack">
<input type="hidden" name="_csrf" value="<?= Security::escape(Csrf::token()) ?>">
<input type="hidden" name="job_id" value="<?= (int)$job['id'] ?>">
<label>Type the domain to confirm<input name="confirm_domain" required value="" autocomplete="off"></label>
<label class="check-label"><input type="checkbox" name="confirm_resources" value="1" required> I inspected the server and confirmed this failed creation has no remaining resources.</label>
<button class="secondary-button" type="submit" name="action" value="retry">Retry this creation</button>
<button class="secondary-button" type="submit" name="action" value="delete" onclick="return confirm('Permanently delete this failed website record and its job history?');">Delete failed record</button>
</form></details>
<?php endif; ?></td>
</tr>
<?php endforeach; ?>
<?php if (!$jobs): ?><tr><td colspan="6">No operations match this filter.</td></tr><?php endif; ?>
</tbody></table></div>
<?php renderFooter(); ?>
