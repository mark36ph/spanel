<?php
declare(strict_types=1);

final class Security
{
    public static function startSession(array $config): void
    {
        // Apply baseline response protections before any page output.
        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('X-Frame-Options: DENY');
            header('Referrer-Policy: strict-origin-when-cross-origin');
            header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
            header('Cache-Control: no-store, private');
            if (str_starts_with((string)($config['app']['base_url'] ?? ''), 'https://')) {
                header('Strict-Transport-Security: max-age=31536000');
            }
        }

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
        ini_set('session.cookie_httponly', '1');
        session_start();

        // Expire inactive administrator sessions and rotate IDs periodically.
        $now = time();
        $lastActivity = (int)($_SESSION['_last_activity'] ?? $now);
        if ($lastActivity > 0 && ($now - $lastActivity) > 1800) {
            $_SESSION = [];
            session_regenerate_id(true);
            $_SESSION['_last_regenerated'] = $now;
        } elseif (($now - (int)($_SESSION['_last_regenerated'] ?? $now)) > 900) {
            session_regenerate_id(true);
            $_SESSION['_last_regenerated'] = $now;
        }
        $_SESSION['_last_activity'] = $now;

        // Do not retain stale one-time database handoff tokens in a session
        // after their matching database record has expired.
        if (array_key_exists('pma_pending_handoff', $_SESSION)) {
            $pendingHandoff = $_SESSION['pma_pending_handoff'];
            $issuedAt = is_array($pendingHandoff) && is_int($pendingHandoff['issued_at'] ?? null)
                ? $pendingHandoff['issued_at']
                : 0;
            if ($issuedAt < 1 || $now < $issuedAt || ($now - $issuedAt) > 60) {
                unset($_SESSION['pma_pending_handoff']);
            }
        }
    }

    public static function escape(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
