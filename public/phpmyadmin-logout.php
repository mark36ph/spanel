<?php
declare(strict_types=1);

$configPath = dirname(__DIR__) . '/config/config.local.php';
if (!is_file($configPath)) {
    http_response_code(500);
    exit('SPanel configuration is unavailable.');
}
$config = require $configPath;
header('Cache-Control: no-store, private');
header('Referrer-Policy: no-referrer');

$securePmaCookie = str_starts_with((string)($config['app']['base_url'] ?? ''), 'https://');
foreach (['spanel_pma_signon', 'phpMyAdmin'] as $cookieName) {
    setcookie($cookieName, '', [
        'expires' => time() - 3600,
        'path' => '/phpmyadmin/',
        'secure' => $securePmaCookie,
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
}
header('Location: /dashboard.php', true, 303);
exit;
