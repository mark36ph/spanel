<?php
declare(strict_types=1);

/**
 * Database-backed sign-in throttling.
 *
 * Only SHA-256 identifiers are persisted; raw email addresses and IP addresses
 * are not stored in the throttle table. Do not trust forwarded proxy headers.
 */
final class LoginRateLimiter
{
    private const WINDOW_MINUTES = 15;
    private const LOCK_MINUTES = 15;
    private const EMAIL_LIMIT = 5;
    private const IP_LIMIT = 30;

    /** @return list<array{scope_type:string,identifier_hash:string,limit:int}> */
    private static function keys(string $email, string $ip): array
    {
        $normalizedEmail = mb_strtolower(trim($email));
        $keys = [[
            'scope_type' => 'email',
            'identifier_hash' => hash('sha256', $normalizedEmail),
            'limit' => self::EMAIL_LIMIT,
        ]];
        if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
            $keys[] = [
                'scope_type' => 'ip',
                'identifier_hash' => hash('sha256', $ip),
                'limit' => self::IP_LIMIT,
            ];
        }
        return $keys;
    }

    public static function isBlocked(PDO $pdo, string $email, string $ip): bool
    {
        $stmt = $pdo->prepare(
            'SELECT 1 FROM login_rate_limits
             WHERE scope_type = :scope_type AND identifier_hash = :identifier_hash
               AND locked_until IS NOT NULL AND locked_until > NOW()
             LIMIT 1'
        );
        foreach (self::keys($email, $ip) as $key) {
            $stmt->execute([
                'scope_type' => $key['scope_type'],
                'identifier_hash' => $key['identifier_hash'],
            ]);
            if ($stmt->fetchColumn() !== false) {
                return true;
            }
        }
        return false;
    }

    public static function recordFailure(PDO $pdo, string $email, string $ip): void
    {
        $keys = self::keys($email, $ip);
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT IGNORE INTO login_rate_limits
                    (scope_type, identifier_hash, attempts, window_started_at)
                 VALUES (:scope_type, :identifier_hash, 0, NOW())'
            );
            foreach ($keys as $key) {
                $insert->execute([
                    'scope_type' => $key['scope_type'],
                    'identifier_hash' => $key['identifier_hash'],
                ]);
            }

            $select = $pdo->prepare(
                'SELECT attempts, (window_started_at < DATE_SUB(NOW(), INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)) AS window_expired,
                        (locked_until IS NOT NULL AND locked_until > NOW()) AS currently_locked
                 FROM login_rate_limits
                 WHERE scope_type = :scope_type AND identifier_hash = :identifier_hash
                 FOR UPDATE'
            );
            $update = $pdo->prepare(
                'UPDATE login_rate_limits
                 SET attempts = :attempts,
                     window_started_at = IF(:reset_window = 1, NOW(), window_started_at),
                     locked_until = IF(:lock_now = 1, DATE_ADD(NOW(), INTERVAL ' . self::LOCK_MINUTES . ' MINUTE), NULL),
                     updated_at = NOW()
                 WHERE scope_type = :scope_type AND identifier_hash = :identifier_hash'
            );

            foreach ($keys as $key) {
                $select->execute([
                    'scope_type' => $key['scope_type'],
                    'identifier_hash' => $key['identifier_hash'],
                ]);
                $row = $select->fetch(PDO::FETCH_ASSOC);
                if (!$row || (int)$row['currently_locked'] === 1) {
                    continue;
                }
                $expired = (int)$row['window_expired'] === 1;
                $attempts = ($expired ? 0 : (int)$row['attempts']) + 1;
                $update->execute([
                    'attempts' => $attempts,
                    'reset_window' => $expired ? 1 : 0,
                    'lock_now' => $attempts >= $key['limit'] ? 1 : 0,
                    'scope_type' => $key['scope_type'],
                    'identifier_hash' => $key['identifier_hash'],
                ]);
            }
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public static function recordSuccess(PDO $pdo, string $email): void
    {
        $normalizedEmail = mb_strtolower(trim($email));
        $stmt = $pdo->prepare(
            "DELETE FROM login_rate_limits WHERE scope_type = 'email' AND identifier_hash = :identifier_hash"
        );
        $stmt->execute(['identifier_hash' => hash('sha256', $normalizedEmail)]);
    }
}
