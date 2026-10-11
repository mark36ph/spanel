<?php
declare(strict_types=1);

require_once __DIR__ . '/Database.php';

final class Auth
{
    public static function user(): ?array
    {
        if (!isset($_SESSION['admin_id'])) {
            return null;
        }
        if (!is_int($_SESSION['admin_id']) || $_SESSION['admin_id'] < 1) {
            $_SESSION = [];
            return null;
        }
        $stmt = Database::connection()->prepare(
            'SELECT id, email, created_at, last_login_at FROM admins WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['id' => $_SESSION['admin_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            // Do not allow an old session to become valid again if an admin is
            // later reactivated. This also removes any pending database handoff.
            $_SESSION = [];
            return null;
        }
        return $user;
    }

    public static function requireLogin(): array
    {
        $user = self::user();
        if (!$user) {
            header('Location: /login.php');
            exit;
        }
        return $user;
    }

    public static function attempt(string $email, string $password): bool
    {
        $stmt = Database::connection()->prepare(
            'SELECT id, password_hash FROM admins WHERE email = :email AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['email' => mb_strtolower(trim($email))]);
        $admin = $stmt->fetch();

        if (!$admin || !password_verify($password, $admin['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['admin_id'] = (int)$admin['id'];
        $update = Database::connection()->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = :id');
        $update->execute(['id' => (int)$admin['id']]);
        return true;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000, 'path' => $p['path'], 'domain' => $p['domain'],
                'secure' => $p['secure'], 'httponly' => $p['httponly'], 'samesite' => 'Strict',
            ]);
        }
        session_destroy();
    }
}
