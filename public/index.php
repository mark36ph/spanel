<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Security.php';
$path = dirname(__DIR__) . '/config/config.local.php';
if (!is_file($path)) {
    http_response_code(500);
    exit('Configuration missing. See docs/DEVELOPMENT.md.');
}
$config = require $path;
Security::startSession($config);
require_once dirname(__DIR__) . '/app/Auth.php';
header('Location: ' . (Auth::user() ? '/dashboard.php' : '/login.php'));
exit;
