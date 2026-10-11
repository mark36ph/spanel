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
require_once dirname(__DIR__) . '/app/Csrf.php';
if (Auth::user()) { header('Location: /dashboard.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Csrf::verify($_POST['_csrf'] ?? null);
    $submittedEmail = mb_strtolower(trim((string)($_POST['email'] ?? '')));
    $remoteAddress = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '';
    $loginBlocked = LoginRateLimiter::isBlocked(Database::connection(), $submittedEmail, $remoteAddress);
    if ($loginBlocked) {
        $error = 'Too many sign-in attempts. Please wait 15 minutes before trying again.';
    } elseif (Auth::attempt($submittedEmail, (string)($_POST['password'] ?? ''))) {
        // Require a fresh database handoff after each SPanel login; do not
        // revive an old phpMyAdmin browser session after logout/deactivation.
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
        header('Location: /dashboard.php'); exit;
    }
    if (!$loginBlocked) {
        usleep(250000);
        $error = 'The email address or password was not recognised.';
    }
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · SPanel</title><link rel="stylesheet" href="/assets/app.css"></head>
<body class="login-page"><div class="login-wrap">
<a class="brand login-brand" href="/"><span class="brand-mark">S</span><span>SPanel<small>Control panel</small></span></a>
<div class="login-card"><div class="eyebrow">ADMINISTRATION</div><h1>Welcome back</h1><p class="muted">Sign in to manage your hosting environment.</p>
<?php if ($error !== ''): ?><div class="alert"><?= Security::escape($error) ?></div><?php endif; ?>
<form method="post" class="form-stack">
<input type="hidden" name="_csrf" value="<?= Security::escape(Csrf::token()) ?>">
<label>Email address<input type="email" name="email" autocomplete="username" required maxlength="254"></label>
<label>Password<input type="password" name="password" autocomplete="current-password" maxlength="1024" required></label>
<button class="primary-button" type="submit">Sign in <span>→</span></button>
</form></div><p class="login-note">Private local build · Never expose to the public internet</p></div></body></html>
