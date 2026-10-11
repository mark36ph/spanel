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

    $password = readHiddenPassword();
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


/**
 * Read a password without echoing it to the terminal. If stdin is not an
 * interactive TTY, fail closed rather than silently reading an exposed secret.
 */
function readHiddenPassword(): string
{
    if (DIRECTORY_SEPARATOR !== '/' || !function_exists('shell_exec')) {
        throw new RuntimeException('Hidden password input requires an interactive Unix terminal.');
    }

    $tty = @shell_exec('tty 2>/dev/null');
    if (!is_string($tty) || trim($tty) === '' || trim($tty) === 'not a tty') {
        throw new RuntimeException('Run this command from an interactive terminal to enter the password safely.');
    }

    fwrite(STDOUT, 'Admin password (minimum 14 characters; input hidden): ');
    $stty = @shell_exec('stty -g 2>/dev/null');
    if (!is_string($stty) || trim($stty) === '') {
        throw new RuntimeException('Could not configure hidden terminal input.');
    }

    try {
        exec('stty -echo');
        $password = fgets(STDIN);
        fwrite(STDOUT, "\n");
        if ($password === false) {
            throw new RuntimeException('Could not read password input.');
        }
        return rtrim($password, "\r\n");
    } finally {
        // Restore terminal echo even if input fails.
        exec('stty ' . escapeshellarg(trim($stty)));
    }
}
