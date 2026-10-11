<?php
declare(strict_types=1);

/** Add an index for bounded stale-operation recovery scans. */
return static function (PDO $pdo): void {
    $exists = $pdo->query(
        "SELECT INDEX_NAME FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = 'operation_jobs'
           AND INDEX_NAME = 'idx_operation_jobs_recovery'"
    )->fetchColumn();

    if (!$exists) {
        $pdo->exec('ALTER TABLE operation_jobs ADD KEY idx_operation_jobs_recovery (status, started_at, id)');
    }
};
