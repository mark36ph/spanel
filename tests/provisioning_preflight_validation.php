<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/ProvisioningPreflight.php';

function preflightMustFail(callable $fn, string $case): void
{
    try {
        $fn();
    } catch (InvalidArgumentException) {
        return;
    }
    fwrite(STDERR, "Expected preflight rejection: {$case}\n");
    exit(1);
}

$facts = [
    'installed_php_versions' => ['8.2', '8.3'],
    'php_fpm_versions' => ['8.2', '8.3'],
    'nginx_config_valid' => true,
    'free_disk_bytes' => 5_000_000_000,
    'required_disk_bytes' => 100_000_000,
    'resource_conflicts' => [],
    'dns_matches' => null,
    'database_available' => true,
];

$result = ProvisioningPreflight::evaluate($facts, null, true);
if ($result['ready'] !== true
    || $result['selected_php_version'] !== '8.3'
    || count($result['checks']) !== 6) {
    fwrite(STDERR, "Expected a ready preflight and preferred PHP 8.3.\n");
    exit(1);
}

$blocked = $facts;
$blocked['resource_conflicts'] = ['spw_42'];
$blocked['free_disk_bytes'] = 1;
$result = ProvisioningPreflight::evaluate($blocked, '8.2', true);
if ($result['ready'] !== false) {
    fwrite(STDERR, "Preflight should block on conflicts and insufficient disk space.\n");
    exit(1);
}

$warning = $facts;
$warning['nginx_config_valid'] = null;
$warning['dns_matches'] = false;
$result = ProvisioningPreflight::evaluate($warning, '8.2', false);
if ($result['ready'] !== true
    || $result['checks'][1]['status'] !== 'warning'
    || $result['checks'][4]['status'] !== 'warning'
    || $result['checks'][5]['status'] !== 'skipped') {
    fwrite(STDERR, "Expected warnings not to block, and unrequested database checks to be skipped.\n");
    exit(1);
}

$invalid = $facts;
$invalid['unexpected'] = true;
preflightMustFail(static fn() => ProvisioningPreflight::evaluate($invalid, null, true), 'extra fields');
$invalid = $facts;
$invalid['installed_php_versions'] = ['8.4'];
preflightMustFail(static fn() => ProvisioningPreflight::evaluate($invalid, null, true), 'unsupported PHP runtime');
$invalid = $facts;
$invalid['resource_conflicts'] = ['spw_42; rm -rf /'];
preflightMustFail(static fn() => ProvisioningPreflight::evaluate($invalid, null, true), 'unsafe conflict identifier');

fwrite(STDOUT, "Provisioning preflight validation checks passed.\n");
