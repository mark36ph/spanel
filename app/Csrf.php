<?php
declare(strict_types=1);

final class Csrf
{
    public static function token(): string
    {
        if (!isset($_SESSION['_csrf']) || !is_string($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['_csrf'];
    }

    public static function verify(?string $submitted): void
    {
        $known = $_SESSION['_csrf'] ?? '';
        if (!is_string($submitted) || !is_string($known) || $known === '' || !hash_equals($known, $submitted)) {
            http_response_code(419);
            exit('Security token expired. Refresh the page and try again.');
        }
    }
}
