<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Security.php';
$configPath = dirname(__DIR__) . '/config/config.local.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit;
}
$config = require $configPath;
Security::startSession($config);
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');
require_once dirname(__DIR__) . '/app/Auth.php';

try {
    if (!Auth::user()) {
        http_response_code(401);
        exit;
    }
    http_response_code(204);
    exit;
} catch (Throwable) {
    // Fail closed without exposing database or session details to phpMyAdmin.
    error_log('SPanel phpMyAdmin authorization check failed.');
    http_response_code(500);
    exit;
}
