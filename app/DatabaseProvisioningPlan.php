<?php
declare(strict_types=1);

/**
 * Data-only plan for per-website MariaDB resources.
 *
 * This class never executes SQL, generates credentials, or writes secrets.
 * A privileged provisioner must independently validate identifiers and apply
 * the exact privilege allowlist. Passwords must never enter this plan or logs.
 */
final class DatabaseProvisioningPlan
{
    private const PRIVILEGES = [
        'SELECT',
        'INSERT',
        'UPDATE',
        'DELETE',
        'CREATE',
        'ALTER',
        'INDEX',
        'REFERENCES',
    ];

    public static function build(int $serverId, int $websiteId): array
    {
        if ($serverId < 1 || $websiteId < 1) {
            throw new InvalidArgumentException('Server and website IDs must be positive.');
        }

        // Identifiers are generated only from numeric IDs, never browser input.
        $databaseName = 'spw_' . $websiteId;
        $databaseUser = 'spu_' . $websiteId;

        if (!preg_match('/^spw_[1-9][0-9]*$/D', $databaseName)
            || !preg_match('/^spu_[1-9][0-9]*$/D', $databaseUser)
            || strlen($databaseName) > 64
            || strlen($databaseUser) > 32) {
            throw new InvalidArgumentException('Generated database identifier is outside supported limits.');
        }

        return [
            'server_id' => $serverId,
            'website_id' => $websiteId,
            'database_name' => $databaseName,
            'database_user' => $databaseUser,
            'database_host' => 'localhost',
            'grant_scope' => $databaseName . '.*',
            'privileges' => self::PRIVILEGES,
            'secret_ref' => 'website-db:' . $serverId . ':' . $websiteId,
            'password_included' => false,
            'execution_enabled' => false,
        ];
    }
}
