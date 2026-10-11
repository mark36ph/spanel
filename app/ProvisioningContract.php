<?php
declare(strict_types=1);

require_once __DIR__ . '/DomainName.php';

/**
 * Exact request/response contract shared by the worker and contract tests.
 * This validates data only; it never executes a privileged operation.
 */
final class ProvisioningContract
{
    private const REQUEST_KEYS = ['operation', 'website_id', 'domain', 'php_version', 'create_database'];
    private const RESPONSE_KEYS = ['ok', 'website_id', 'domain', 'php_version', 'database_created'];

    public static function validateRequest(array $request): array
    {
        $keys = array_keys($request);
        sort($keys);
        $expected = self::REQUEST_KEYS;
        sort($expected);
        if ($keys !== $expected
            || ($request['operation'] ?? null) !== 'website.provision'
            || !is_int($request['website_id'] ?? null)
            || $request['website_id'] < 1
            || !is_string($request['domain'] ?? null)
            || DomainName::normalize($request['domain']) !== $request['domain']
            || (($request['php_version'] ?? null) !== null
                && !in_array($request['php_version'], ['8.2', '8.3'], true))
            || !is_bool($request['create_database'] ?? null)) {
            throw new InvalidArgumentException('Provisioning request does not match the supported contract.');
        }

        return [
            'operation' => 'website.provision',
            'website_id' => $request['website_id'],
            'domain' => $request['domain'],
            'php_version' => $request['php_version'],
            'create_database' => $request['create_database'],
        ];
    }

    public static function validateResponse(array $response, array $request): array
    {
        $request = self::validateRequest($request);
        $keys = array_keys($response);
        sort($keys);
        $expected = self::RESPONSE_KEYS;
        sort($expected);

        $actualPhp = $response['php_version'] ?? null;
        $phpMatches = $request['php_version'] === null
            ? in_array($actualPhp, ['8.2', '8.3'], true)
            : $actualPhp === $request['php_version'];

        if ($keys !== $expected
            || ($response['ok'] ?? null) !== true
            || ($response['website_id'] ?? null) !== $request['website_id']
            || ($response['domain'] ?? null) !== $request['domain']
            || !$phpMatches
            || !is_bool($response['database_created'] ?? null)
            || $response['database_created'] !== $request['create_database']) {
            throw new InvalidArgumentException('Provisioning helper response does not match the request.');
        }

        return [
            'ok' => true,
            'website_id' => $request['website_id'],
            'domain' => $request['domain'],
            'php_version' => $actualPhp,
            'database_created' => $response['database_created'],
        ];
    }
}
