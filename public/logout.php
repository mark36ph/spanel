<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/Security.php';
$path = dirname(__DIR__) . '/config/config.local.php';
if (!is_file($path)) { http_response_code(500); exit('Configuration missing.'); }
Security::startSession(require $path);
require_once dirname(__DIR__) . '/app/Auth.php';
require_once dirname(__DIR__) . '/app/Csrf.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /dashboard.php'); exit; }
Csrf::verify($_POST['_csrf'] ?? null);
Auth::logout();
header('Location: /login.php');
exit;
