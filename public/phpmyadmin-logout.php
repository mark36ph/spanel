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

setcookie('spanel_pma_signon', '', [
    'expires' => time() - 3600,
    'path' => '/phpmyadmin/',
    'secure' => str_starts_with((string)($config['app']['base_url'] ?? ''), 'https://'),
    'httponly' => true,
    'samesite' => 'Strict',
]);
header('Location: /dashboard.php', true, 303);
exit;
