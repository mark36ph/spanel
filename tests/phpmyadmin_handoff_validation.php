<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/PhpMyAdminHandoff.php';

$pdo = new class extends PDO {
    public function __construct() {}
};

foreach (['', 'short', str_repeat('g', 64), str_repeat('a', 63), str_repeat('a', 65)] as $token) {
    if (PhpMyAdminHandoff::consume($pdo, $token, 1) !== null) {
        fwrite(STDERR, "Invalid handoff token was accepted.\n");
        exit(1);
    }
}

foreach ([[0, 1], [1, 0], [-1, 1]] as [$adminId, $databaseId]) {
    try {
        PhpMyAdminHandoff::issue($pdo, $adminId, $databaseId);
    } catch (InvalidArgumentException) {
        continue;
    }
    fwrite(STDERR, "Invalid handoff ownership IDs were accepted.\n");
    exit(1);
}

try {
    PhpMyAdminHandoff::issue($pdo, 1, 1, 'not-an-ip');
} catch (InvalidArgumentException) {
    fwrite(STDOUT, "phpMyAdmin handoff input checks passed.\n");
    exit(0);
}

fwrite(STDERR, "Invalid remote address was accepted.\n");
exit(1);
