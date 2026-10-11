<?php
declare(strict_types=1);

/**
 * Storage primitives for a short-lived, single-use phpMyAdmin handoff.
 *
 * This class does not authenticate to phpMyAdmin and must not be treated as
 * the complete login integration. The controller must authenticate the admin,
 * authorize access to the selected database, and keep the returned raw token
 * only in server-side session storage for the launch flow. Never pass it through
 * the browser, place it in a URL, or log it.
 */
final class PhpMyAdminHandoff
{
    public const TOKEN_TTL_SECONDS = 60;

    /**
     * Create a one-time token. Only the SHA-256 hash is persisted.
     * The caller must have already checked admin and database ownership.
     */
    public static function issue(PDO $pdo, int $adminId, int $databaseId, ?string $remoteAddr = null): string
    {
        if ($adminId < 1 || $databaseId < 1) {
            throw new InvalidArgumentException('Administrator and database IDs must be positive.');
        }
        if ($remoteAddr !== null && (strlen($remoteAddr) > 45 || filter_var($remoteAddr, FILTER_VALIDATE_IP) === false)) {
            throw new InvalidArgumentException('Remote address is invalid.');
        }

        $token = bin2hex(random_bytes(32));
        $hash = hash('sha256', $token);

        $pdo->beginTransaction();
        try {
            // Opportunistic cleanup is bounded to expired/consumed records only.
            $pdo->exec('DELETE FROM phpmyadmin_handoffs WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY) OR consumed_at < DATE_SUB(NOW(), INTERVAL 1 DAY)');
            $stmt = $pdo->prepare(
                'INSERT INTO phpmyadmin_handoffs
                    (token_hash, admin_id, website_database_id, expires_at, remote_addr)
                 VALUES
                    (:token_hash, :admin_id, :database_id, DATE_ADD(NOW(), INTERVAL 60 SECOND), :remote_addr)'
            );
            $stmt->execute([
                'token_hash' => $hash,
                'admin_id' => $adminId,
                'database_id' => $databaseId,
                'remote_addr' => $remoteAddr,
            ]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $token;
    }

    /**
     * Revoke any outstanding handoffs for a database after credential rotation
     * or an ownership/security change. The raw tokens are never needed.
     */
    public static function revokeForDatabase(PDO $pdo, int $databaseId): int
    {
        if ($databaseId < 1) {
            throw new InvalidArgumentException('Database ID must be positive.');
        }
        $stmt = $pdo->prepare(
            'UPDATE phpmyadmin_handoffs SET consumed_at = NOW()
             WHERE website_database_id = :database_id AND consumed_at IS NULL'
        );
        $stmt->execute(['database_id' => $databaseId]);
        return $stmt->rowCount();
    }

    /** Revoke all outstanding handoffs when an administrator is disabled. */
    public static function revokeForAdmin(PDO $pdo, int $adminId): int
    {
        if ($adminId < 1) {
            throw new InvalidArgumentException('Administrator ID must be positive.');
        }
        $stmt = $pdo->prepare(
            'UPDATE phpmyadmin_handoffs SET consumed_at = NOW()
             WHERE admin_id = :admin_id AND consumed_at IS NULL'
        );
        $stmt->execute(['admin_id' => $adminId]);
        return $stmt->rowCount();
    }

    /**
     * Atomically consume a token for its original administrator.
     * Returns only IDs needed for a subsequent authorization check.
     */
    public static function consume(PDO $pdo, string $token, int $adminId): ?array
    {
        if ($adminId < 1 || !preg_match('/^[a-f0-9]{64}$/D', $token)) {
            return null;
        }

        $hash = hash('sha256', $token);
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'SELECT id, admin_id, website_database_id
                 FROM phpmyadmin_handoffs
                 WHERE token_hash = :token_hash
                   AND admin_id = :admin_id
                   AND consumed_at IS NULL
                   AND expires_at > NOW()
                 LIMIT 1 FOR UPDATE'
            );
            $stmt->execute(['token_hash' => $hash, 'admin_id' => $adminId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                $pdo->commit();
                return null;
            }

            $consume = $pdo->prepare(
                'UPDATE phpmyadmin_handoffs SET consumed_at = NOW()
                 WHERE id = :id AND consumed_at IS NULL AND expires_at > NOW()'
            );
            $consume->execute(['id' => (int)$row['id']]);
            if ($consume->rowCount() !== 1) {
                $pdo->rollBack();
                return null;
            }

            $pdo->commit();
            return [
                'admin_id' => (int)$row['admin_id'],
                'website_database_id' => (int)$row['website_database_id'],
            ];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
