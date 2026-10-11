<?php
declare(strict_types=1);
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/Csrf.php';
require_once __DIR__ . '/Auth.php';

function renderHeader(string $title, string $active = 'dashboard'): void
{
    $config = require dirname(__DIR__) . '/config/config.local.php';
    $user = Auth::user();
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<title>' . Security::escape($title) . ' · SPanel</title>';
    echo '<link rel="stylesheet" href="/assets/app.css"></head><body><div class="shell">';
    $activeItem = $active;
    include dirname(__DIR__) . '/public/partials/sidebar.php';
    echo '<main class="main"><header class="topbar"><div><span class="eyebrow">HOSTING CONTROL</span>';
    echo '<h1>' . Security::escape($title) . '</h1></div>';
    if ($user) {
        echo '<div class="user-chip"><span class="status-dot"></span>' . Security::escape($user['email']) . '</div>';
    }
    echo '</header><section class="content">';
}

function renderFooter(): void
{
    echo '</section><footer class="footer">SPanel · Local development build · Keep private until security review</footer></main></div></body></html>';
}
