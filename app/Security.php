<?php
declare(strict_types=1);

final class Security
{
    public static function startSession(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        session_name($config['app']['session_name'] ?? 'spanel_session');
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => str_starts_with((string)($config['app']['base_url'] ?? ''), 'https://'),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_start();
    }

    public static function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
