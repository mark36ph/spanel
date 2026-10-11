<?php
declare(strict_types=1);

/**
 * Evaluates facts collected by a future restricted preflight operation.
 * This class is data-only: it does not inspect the OS or execute commands.
 */
final class ProvisioningPreflight
{
    private const KEYS = [
        'installed_php_versions',
        'php_fpm_versions',
        'nginx_config_valid',
        'free_disk_bytes',
        'required_disk_bytes',
        'resource_conflicts',
        'dns_matches',
        'database_available',
    ];

    public static function evaluate(array $facts, ?string $requestedPhpVersion, bool $createDatabase): array
    {
        $keys = array_keys($facts);
        sort($keys);
        $expected = self::KEYS;
        sort($expected);

        if ($keys !== $expected
            || ($requestedPhpVersion !== null && !in_array($requestedPhpVersion, ['8.2', '8.3'], true))
            || !self::validVersions($facts['installed_php_versions'] ?? null)
            || !self::validVersions($facts['php_fpm_versions'] ?? null)
            || !in_array($facts['nginx_config_valid'] ?? null, [true, false, null], true)
            || !is_int($facts['free_disk_bytes'] ?? null) || $facts['free_disk_bytes'] < 0
            || !is_int($facts['required_disk_bytes'] ?? null) || $facts['required_disk_bytes'] < 1
            || !is_array($facts['resource_conflicts'] ?? null)
            || !array_is_list($facts['resource_conflicts'])
            || !self::validConflicts($facts['resource_conflicts'])
            || !in_array($facts['dns_matches'] ?? null, [true, false, null], true)
            || !is_bool($facts['database_available'] ?? null)) {
            throw new InvalidArgumentException('Preflight facts do not match the supported contract.');
        }

        $installed = $facts['installed_php_versions'];
        $fpm = $facts['php_fpm_versions'];
        $selectedPhp = $requestedPhpVersion;
        if ($selectedPhp === null) {
            foreach (['8.3', '8.2'] as $candidate) {
                if (in_array($candidate, $installed, true) && in_array($candidate, $fpm, true)) {
                    $selectedPhp = $candidate;
                    break;
                }
            }
        }

        $checks = [];
        $checks[] = self::check(
            'php_runtime',
            $selectedPhp !== null && in_array($selectedPhp, $installed, true) && in_array($selectedPhp, $fpm, true)
                ? 'pass' : 'fail',
            $selectedPhp !== null ? 'PHP ' . $selectedPhp . ' CLI and FPM are available.' : 'No supported PHP runtime is available.'
        );
        $nginxStatus = $facts['nginx_config_valid'] === true ? 'pass'
            : ($facts['nginx_config_valid'] === false ? 'fail' : 'warning');
        $checks[] = self::check('nginx_config', $nginxStatus, match ($nginxStatus) {
            'pass' => 'Nginx configuration test passed.',
            'fail' => 'Nginx configuration test failed.',
            default => 'Nginx configuration test has not been run.',
        });
        $diskOk = $facts['free_disk_bytes'] >= $facts['required_disk_bytes'];
        $checks[] = self::check('disk_space', $diskOk ? 'pass' : 'fail', $diskOk
            ? 'Sufficient free disk space.'
            : 'Insufficient free disk space for the requested operation.');
        $noConflicts = $facts['resource_conflicts'] === [];
        $checks[] = self::check('resource_conflicts', $noConflicts ? 'pass' : 'fail', $noConflicts
            ? 'No resource-name conflicts detected.'
            : 'Existing resources conflict with the proposed website: ' . implode(', ', $facts['resource_conflicts']));
        $dnsStatus = $facts['dns_matches'] === true ? 'pass'
            : ($facts['dns_matches'] === false ? 'warning' : 'warning');
        $checks[] = self::check('dns', $dnsStatus, match ($facts['dns_matches']) {
            true => 'DNS matches the target server.',
            false => 'DNS does not currently match the target server; provisioning may continue before cutover.',
            null => 'DNS status is unknown; verify it before requesting SSL.',
        });
        $databaseStatus = !$createDatabase ? 'skipped'
            : ($facts['database_available'] ? 'pass' : 'fail');
        $checks[] = self::check('database', $databaseStatus, match ($databaseStatus) {
            'skipped' => 'Database creation was not requested.',
            'pass' => 'Database service is available.',
            default => 'Database service is unavailable.',
        });

        $ready = true;
        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                $ready = false;
                break;
            }
        }

        return [
            'ready' => $ready,
            'selected_php_version' => $selectedPhp,
            'checks' => $checks,
        ];
    }

    private static function validVersions(mixed $value): bool
    {
        if (!is_array($value) || !array_is_list($value)) {
            return false;
        }
        foreach ($value as $version) {
            if (!is_string($version) || !in_array($version, ['8.2', '8.3'], true)) {
                return false;
            }
        }
        return count($value) === count(array_unique($value));
    }

    private static function validConflicts(array $conflicts): bool
    {
        foreach ($conflicts as $conflict) {
            if (!is_string($conflict)
                || preg_match('/^[a-zA-Z0-9_.:-]{1,100}$/D', $conflict) !== 1) {
                return false;
            }
        }
        return true;
    }

    private static function check(string $name, string $status, string $message): array
    {
        return ['name' => $name, 'status' => $status, 'message' => $message];
    }
}
