<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Security.php';
$configPath = dirname(__DIR__) . '/config/config.local.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('SPanel configuration is unavailable.');
}
$config = require $configPath;
Security::startSession($config);
header('Referrer-Policy: no-referrer');
require_once dirname(__DIR__) . '/app/Auth.php';

$admin = Auth::requireLogin();
$pending = $_SESSION['pma_pending_handoff'] ?? null;
unset($_SESSION['pma_pending_handoff']);

$websiteId = is_array($pending) && is_int($pending['website_id'] ?? null) ? $pending['website_id'] : 0;
$remoteAddress = $_SERVER['REMOTE_ADDR'] ?? null;
if (!is_string($remoteAddress) || filter_var($remoteAddress, FILTER_VALIDATE_IP) === false) {
    $remoteAddress = null;
}
$validPending = is_array($pending)
    && is_int($pending['database_id'] ?? null)
    && $pending['database_id'] > 0
    && is_int($pending['website_id'] ?? null)
    && $pending['website_id'] > 0
    && is_int($pending['issued_at'] ?? null)
    && time() - $pending['issued_at'] >= 0
    && time() - $pending['issued_at'] <= 60
    && is_string($pending['token'] ?? null)
    && preg_match('/^[a-f0-9]{64}$/D', $pending['token']) === 1
    && (($pending['remote_addr'] ?? null) === $remoteAddress);

if (!$validPending || $websiteId < 1 || (int)$admin['id'] < 1) {
    session_write_close();
    header('Location: /site.php?id=' . max(1, $websiteId) . '&pma=error', true, 303);
    exit;
}
session_write_close();

$sessionId = bin2hex(random_bytes(32));
$request = [
    'operation' => 'database.handoff',
    'admin_id' => (int)$admin['id'],
    'database_id' => $pending['database_id'],
    'token' => $pending['token'],
    'remote_addr' => $remoteAddress,
    'signon_session_id' => $sessionId,
];
$encoded = json_encode($request, JSON_THROW_ON_ERROR);
if (strlen($encoded) > 4096) {
    header('Location: /site.php?id=' . $websiteId . '&pma=error', true, 303);
    exit;
}

$pipes = [];
$process = @proc_open(
    ['/usr/bin/sudo', '-n', '/usr/local/sbin/spanel-pma-credential-broker'],
    [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['file', '/dev/null', 'w'],
    ],
    $pipes,
    null,
    ['PATH' => '/usr/bin:/bin', 'LANG' => 'C', 'LC_ALL' => 'C']
);
if (!is_resource($process)) {
    error_log('SPanel phpMyAdmin handoff could not start the restricted broker.');
    header('Location: /site.php?id=' . $websiteId . '&pma=error', true, 303);
    exit;
}

$written = 0;
while ($written < strlen($encoded)) {
    $count = fwrite($pipes[0], substr($encoded, $written));
    if ($count === false || $count === 0) {
        proc_terminate($process, 9);
        fclose($pipes[0]);
        fclose($pipes[1]);
        proc_close($process);
        error_log('SPanel phpMyAdmin handoff request could not be sent.');
        header('Location: /site.php?id=' . $websiteId . '&pma=error', true, 303);
        exit;
    }
    $written += $count;
}
fclose($pipes[0]);
stream_set_timeout($pipes[1], 10);
$output = stream_get_contents($pipes[1], 4097);
$meta = stream_get_meta_data($pipes[1]);
fclose($pipes[1]);
$exitCode = proc_close($process);

try {
    if (($meta['timed_out'] ?? false) || !is_string($output) || strlen($output) > 4096 || $exitCode !== 0) {
        throw new RuntimeException('broker_failed');
    }
    $response = json_decode($output, true, 8, JSON_THROW_ON_ERROR);
    $keys = is_array($response) ? array_keys($response) : [];
    sort($keys);
    if ($keys !== ['database_name', 'ok', 'session_id']
        || ($response['ok'] ?? null) !== true
        || ($response['session_id'] ?? null) !== $sessionId
        || ($response['database_name'] ?? null) !== 'spw_' . $websiteId) {
        throw new RuntimeException('broker_response_invalid');
    }
} catch (Throwable) {
    error_log('SPanel phpMyAdmin handoff failed closed; broker response rejected.');
    header('Location: /site.php?id=' . $websiteId . '&pma=error', true, 303);
    exit;
}

$secureCookie = str_starts_with((string)($config['app']['base_url'] ?? ''), 'https://');
setcookie('spanel_pma_signon', $sessionId, [
    'expires' => time() + 60,
    'path' => '/phpmyadmin/',
    'secure' => $secureCookie,
    'httponly' => true,
    'samesite' => 'Strict',
]);
header('Cache-Control: no-store, private');
header('Location: /phpmyadmin/index.php?db=' . rawurlencode($response['database_name']), true, 303);
exit;
