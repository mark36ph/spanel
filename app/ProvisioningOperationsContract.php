<?php
declare(strict_types=1);

require_once __DIR__ . '/DomainName.php';
require_once __DIR__ . '/ProvisioningPreflight.php';

/**
 * Data-only contract between SPanel and a future restricted operations service.
 * It validates a read-only preflight response and never runs OS commands.
 */
final class ProvisioningOperationsContract
{
    private const REQUEST_KEYS = [
        'operation',
        'website_id',
        'domain',
        'php_version',
        'create_database',
        'required_disk_bytes',
    ];

    private const RESPONSE_KEYS = ['ok', 'website_id', 'domain', 'facts'];

    public static function validatePreflightRequest(array $request): array
    {
        $keys = array_keys($request);
        sort($keys);
        $expected = self::REQUEST_KEYS;
        sort($expected);

        if ($keys !== $expected
            || ($request['operation'] ?? null) !== 'website.preflight'
            || !is_int($request['website_id'] ?? null)
            || $request['website_id'] < 1
            || !is_string($request['domain'] ?? null)
            || DomainName::normalize($request['domain']) !== $request['domain']
            || (($request['php_version'] ?? null) !== null
                && !in_array($request['php_version'], ['8.2', '8.3'], true))
            || !is_bool($request['create_database'] ?? null)
            || !is_int($request['required_disk_bytes'] ?? null)
            || $request['required_disk_bytes'] < 1) {
            throw new InvalidArgumentException('Preflight request does not match the supported contract.');
        }

        return [
            'operation' => 'website.preflight',
            'website_id' => $request['website_id'],
            'domain' => $request['domain'],
            'php_version' => $request['php_version'],
            'create_database' => $request['create_database'],
            'required_disk_bytes' => $request['required_disk_bytes'],
        ];
    }

    public static function validatePreflightResponse(array $response, array $request): array
    {
        $request = self::validatePreflightRequest($request);
        $keys = array_keys($response);
        sort($keys);
        $expected = self::RESPONSE_KEYS;
        sort($expected);

        if ($keys !== $expected
            || ($response['ok'] ?? null) !== true
            || ($response['website_id'] ?? null) !== $request['website_id']
            || ($response['domain'] ?? null) !== $request['domain']
            || !is_array($response['facts'] ?? null)) {
            throw new InvalidArgumentException('Preflight response does not match the request.');
        }

        $facts = $response['facts'];
        if (($facts['required_disk_bytes'] ?? null) !== $request['required_disk_bytes']) {
            throw new InvalidArgumentException('Preflight response disk requirement does not match the request.');
        }

        $evaluation = ProvisioningPreflight::evaluate(
            $facts,
            $request['php_version'],
            $request['create_database']
        );

        return [
            'ok' => true,
            'website_id' => $request['website_id'],
            'domain' => $request['domain'],
            'evaluation' => $evaluation,
        ];
    }
}
