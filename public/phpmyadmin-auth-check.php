<?php
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config/config.local.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit;
}
$config = require $configPath;
$sessionName = (string)($config['app']['session_name'] ?? 'spanel_session');
$cookieId = $_COOKIE[$sessionName] ?? null;

// Do not create new PHP sessions for anonymous Nginx auth subrequests.
if (!is_string($cookieId) || strlen($cookieId) < 16 || strlen($cookieId) > 256
    || preg_match('/^[A-Za-z0-9,-]+$/D', $cookieId) !== 1) {
    http_response_code(401);
    exit;
}

session_name($sessionName);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => str_starts_with((string)($config['app']['base_url'] ?? ''), 'https://'),
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
session_start();
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$now = time();
$lastActivity = $_SESSION['_last_activity'] ?? null;
if (!is_int($lastActivity) || $lastActivity < 1 || $now < $lastActivity || ($now - $lastActivity) > 1800) {
    session_write_close();
    http_response_code(401);
    exit;
}

require_once dirname(__DIR__) . '/app/Auth.php';
try {
    if (!Auth::user()) {
        session_write_close();
        http_response_code(401);
        exit;
    }
    // This check is called for every phpMyAdmin request. Refresh the idle
    // timestamp, but do not rotate the ID here because Nginx auth subrequests
    // cannot forward a Set-Cookie header to the browser.
    $_SESSION['_last_activity'] = $now;
    session_write_close();
    http_response_code(204);
    exit;
} catch (Throwable) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    error_log('SPanel phpMyAdmin authorization check failed.');
    http_response_code(500);
    exit;
}
