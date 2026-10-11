<?php
declare(strict_types=1);

/**
 * Conservative recovery for operations interrupted while in the running state.
 * Never requeues a job automatically: system resources may already exist.
 */
final class OperationRecovery
{
    public const DEFAULT_STALE_MINUTES = 15;
    public const MAX_BATCH_SIZE = 500;

    public static function validateStaleMinutes(int $minutes): int
    {
        if ($minutes < 5 || $minutes > 1440) {
            throw new InvalidArgumentException('Stale minutes must be between 5 and 1440.');
        }
        return $minutes;
    }

    /**
     * Dry-run by default. Apply mode marks stale jobs rollback_required in a
     * single transaction and returns the affected job IDs.
     *
     * @return int[] Job IDs found or marked for manual recovery.
     */
    public static function reconcile(PDO $pdo, int $minutes, bool $apply = false): array
    {
        self::validateStaleMinutes($minutes);
        $pdo->beginTransaction();
        try {
            // minutes is an already-validated integer, not external SQL text.
            $sql = "SELECT id FROM operation_jobs
                    WHERE status = 'running'
                      AND started_at IS NOT NULL
                      AND started_at < DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)
                    ORDER BY id ASC
                    LIMIT " . self::MAX_BATCH_SIZE . "
                    FOR UPDATE";
            $ids = array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN));
            if (!$apply || !$ids) {
                $pdo->commit();
                return $ids;
            }

            $update = $pdo->prepare(
                "UPDATE operation_jobs
                 SET status = 'rollback_required',
                     error_code = 'worker_interrupted',
                     error_summary = 'The worker stopped or timed out while this job was running. Inspect server resources before retrying.',
                     finished_at = NOW(),
                     updated_at = NOW()
                 WHERE id = :id AND status = 'running'
                   AND started_at IS NOT NULL
                   AND started_at < DATE_SUB(NOW(), INTERVAL {$minutes} MINUTE)"
            );
            $changed = [];
            foreach ($ids as $id) {
                $update->execute(['id' => $id]);
                if ($update->rowCount() === 1) {
                    $changed[] = $id;
                }
            }
            $pdo->commit();
            return $changed;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
