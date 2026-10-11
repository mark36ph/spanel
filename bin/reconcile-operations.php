<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/app/Database.php';
require_once dirname(__DIR__) . '/app/OperationRecovery.php';

$apply = false;
$minutes = OperationRecovery::DEFAULT_STALE_MINUTES;
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--apply') {
        $apply = true;
    } elseif (preg_match('/^--minutes=(\d{1,4})$/D', $arg, $match) === 1) {
        $minutes = (int)$match[1];
    } else {
        fwrite(STDERR, "Usage: php bin/reconcile-operations.php [--minutes=15] [--apply]\n");
        fwrite(STDERR, "Default mode is read-only. --apply marks stale running jobs as rollback_required; it never retries them.\n");
        exit(2);
    }
}
try {
    OperationRecovery::validateStaleMinutes($minutes);
    $pdo = Database::connection();
    $ids = OperationRecovery::reconcile($pdo, $minutes, $apply);
    if (!$ids) {
        fwrite(STDOUT, "No stale running jobs found.\n");
        exit(0);
    }

    fwrite(STDOUT, ($apply ? 'Marked for manual recovery' : 'Would mark for manual recovery') . ': ' . implode(', ', $ids) . "\n");
    fwrite(STDOUT, "These jobs were not retried. Inspect the host and resources before deciding on a recovery action.\n");
    if (!$apply) {
        fwrite(STDOUT, "Run with --apply only after reviewing this list.\n");
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Operation reconciliation failed: ' . mb_substr($e->getMessage(), 0, 300) . "\n");
    exit(1);
}
