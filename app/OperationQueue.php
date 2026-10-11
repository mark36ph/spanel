<?php
declare(strict_types=1);

/**
 * Typed, allowlisted operation queue. This class records requests only; it
 * never executes commands or contacts a hosting server.
 */
final class OperationQueue
{
    private const OPERATION_SCHEMAS = [
        'website.provision' => [
            'required' => ['create_database_requested', 'php_version_requested'],
        ],
    ];

    /**
     * Validate and normalize an operation payload before it can enter the DB.
     *
     * @return array<string, bool|string|null>
     */
    public static function validatePayload(string $operationType, array $payload): array
    {
        if (!isset(self::OPERATION_SCHEMAS[$operationType])) {
            throw new InvalidArgumentException('Operation type is not allowlisted.');
        }

        $schema = self::OPERATION_SCHEMAS[$operationType];
        $keys = array_keys($payload);
        sort($keys);
        $expected = $schema['required'];
        sort($expected);
        if ($keys !== $expected) {
            throw new InvalidArgumentException('Operation payload contains missing or unsupported fields.');
        }

        if (!is_bool($payload['create_database_requested'])) {
            throw new InvalidArgumentException('create_database_requested must be a boolean.');
        }
        $phpVersion = $payload['php_version_requested'];
        if ($phpVersion !== null && !in_array($phpVersion, ['8.2', '8.3'], true)) {
            throw new InvalidArgumentException('php_version_requested must be null, 8.2 or 8.3.');
        }

        return [
            'create_database_requested' => $payload['create_database_requested'],
            'php_version_requested' => $phpVersion,
        ];
    }

    public static function enqueueWebsiteProvision(
        PDO $pdo,
        int $serverId,
        int $websiteId,
        int $adminId,
        bool $createDatabase,
        ?string $phpVersion
    ): int {
        if ($serverId < 1 || $websiteId < 1 || $adminId < 1) {
            throw new InvalidArgumentException('Server, website and administrator IDs must be positive.');
        }

        $payload = self::validatePayload('website.provision', [
            'create_database_requested' => $createDatabase,
            'php_version_requested' => $phpVersion,
        ]);
        $encodedPayload = json_encode($payload, JSON_THROW_ON_ERROR);

        $stmt = $pdo->prepare(
            "INSERT INTO operation_jobs
                (server_id, website_id, operation_type, status, payload_json, requested_by_admin_id, idempotency_key)
             VALUES
                (:server_id, :website_id, 'website.provision', 'queued', :payload, :admin_id, :idempotency_key)"
        );
        $stmt->execute([
            'server_id' => $serverId,
            'website_id' => $websiteId,
            'payload' => $encodedPayload,
            'admin_id' => $adminId,
            'idempotency_key' => 'website-provision:' . $websiteId,
        ]);

        return (int)$pdo->lastInsertId();
    }
}
