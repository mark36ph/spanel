<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Security.php';
$path = dirname(__DIR__) . '/config/config.local.php';
if (!is_file($path)) { http_response_code(500); exit('Configuration missing.'); }
$config = require $path;
Security::startSession($config);
require_once dirname(__DIR__) . '/app/Auth.php';
require_once dirname(__DIR__) . '/app/Csrf.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /dashboard.php'); exit; }
Csrf::verify($_POST['_csrf'] ?? null);
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
Auth::logout();
header('Location: /login.php');
exit;
