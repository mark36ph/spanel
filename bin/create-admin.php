<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Database.php';

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(1);
}

try {
    $email = strtolower(trim((string)readline('Admin email: ')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
        throw new RuntimeException('Enter a valid email address.');
    }

    fwrite(STDOUT, 'Admin password (minimum 14 characters; terminal input may be visible): ');
    $password = (string)readline();
    if (strlen($password) < 14) {
        throw new RuntimeException('Use a password with at least 14 characters.');
    }

    $stmt = Database::connection()->prepare(
        'INSERT INTO admins (email, password_hash, is_active) VALUES (:email, :hash, 1)'
    );
    $stmt->execute(['email' => $email, 'hash' => password_hash($password, PASSWORD_DEFAULT)]);
    fwrite(STDOUT, "Administrator created.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not create administrator: ' . $e->getMessage() . "\n");
    exit(1);
}
