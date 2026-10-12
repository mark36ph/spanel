<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Security.php';
$config = require dirname(__DIR__) . '/config/config.local.php';
Security::startSession($config);
require_once dirname(__DIR__) . '/app/View.php';
Auth::requireLogin();

$repoRoot = dirname(__DIR__);
$installedCommit = 'unknown';
$gitHead = $repoRoot . '/.git/HEAD';
if (is_file($gitHead) && is_readable($gitHead)) {
    $head = trim((string)file_get_contents($gitHead));
    if (preg_match('/^ref: refs\/heads\/[A-Za-z0-9._\/-]+$/', $head)) {
        $refPath = $repoRoot . '/.git/' . substr($head, 5);
        if (is_file($refPath) && is_readable($refPath)) {
            $candidate = trim((string)file_get_contents($refPath));
            if (preg_match('/^[a-f0-9]{40}$/', $candidate)) {
                $installedCommit = substr($candidate, 0, 12);
            }
        }
    } elseif (preg_match('/^[a-f0-9]{40}$/', $head)) {
        $installedCommit = substr($head, 0, 12);
    }
}
$updaterPath = '/usr/local/sbin/spanel-update';
$updaterReady = is_executable($updaterPath);
renderHeader('Updates', 'updates');
?>
<div class="welcome-row"><div><p class="muted">SYSTEM MAINTENANCE</p><h2>SPanel updates</h2><p class="muted">Review the installed build and update readiness.</p></div></div>
<div class="stat-grid">
<article class="stat-card"><div class="stat-top"><span>Installed commit</span><span class="stat-icon">⌘</span></div><strong class="env-value"><?= Security::escape($installedCommit) ?></strong><small>Read from the installed Git metadata when available</small></article>
<article class="stat-card"><div class="stat-top"><span>Update mechanism</span><span class="stat-icon amber">↻</span></div><strong class="env-value"><?= $updaterReady ? 'Detected' : 'Not installed' ?></strong><small><?= $updaterReady ? 'Restricted updater helper is present' : 'Safe updater helper is not installed yet' ?></small></article>
</div>
<div class="section-heading"><div><h3>Update from GitHub</h3><p class="muted">Updates must use a fixed repository and a restricted root-owned helper.</p></div></div>
<div class="notice"><strong><?= $updaterReady ? 'Updater helper detected' : 'One-time setup still required' ?></strong><p><?= $updaterReady ? 'The restricted helper exists. Applying updates remains disabled in this initial UI until the helper protocol and backup/rollback checks are verified.' : 'The in-panel update button is intentionally disabled until the restricted updater, sudo policy, backup/rollback checks and audit logging are installed. The panel must never run git, shell commands or arbitrary code as root directly from a browser request.' ?></p></div>
<div class="login-card"><h3>Update from the VM terminal for now</h3><p class="muted">Run this as your normal VM user to fetch the latest committed SPanel code. It does not deploy the code into /opt/spanel by itself.</p><pre class="file-preview">cd /home/mark/spanel
git pull --ff-only origin main</pre><p class="muted">After pulling, deploy only through the reviewed update procedure. Do not copy over config/config.local.php, database secrets, website files or databases.</p></div>
<?php renderFooter(); ?>
