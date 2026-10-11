<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/ProvisioningOperationsContract.php';
require_once dirname(__DIR__) . '/app/ProvisioningWorkflow.php';

function operationsMustFail(callable $fn, string $case): void
{
    try {
        $fn();
    } catch (InvalidArgumentException) {
        return;
    }
    fwrite(STDERR, "Expected operations contract rejection: {$case}\n");
    exit(1);
}

$request = [
    'operation' => 'website.preflight',
    'website_id' => 42,
    'domain' => 'example.com',
    'php_version' => null,
    'create_database' => true,
    'required_disk_bytes' => 100_000_000,
];
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
$validRequest = ProvisioningOperationsContract::validatePreflightRequest($request);
$response = ProvisioningOperationsContract::validatePreflightResponse([
    'ok' => true,
    'website_id' => 42,
    'domain' => 'example.com',
    'facts' => $facts,
], $validRequest);
$workflow = ProvisioningWorkflow::build($response, true);
if ($workflow['execution_enabled'] !== false
    || $workflow['php_version'] !== '8.3'
    || $workflow['resources']['database_name'] !== 'spw_42'
    || $workflow['steps'][0]['operation'] !== 'create_user'
    || $workflow['steps'][count($workflow['steps']) - 1]['operation'] !== 'health_check_site') {
    fwrite(STDERR, "Expected a data-only ordered provisioning plan.\n");
    exit(1);
}

$withoutDatabase = ProvisioningWorkflow::build($response, false);
foreach ($withoutDatabase['steps'] as $step) {
    if ($step['operation'] === 'create_database') {
        fwrite(STDERR, "Database step must be omitted when not requested.\n");
        exit(1);
    }
}
if ($withoutDatabase['resources']['database_name'] !== null) {
    fwrite(STDERR, "Database resources must be omitted when not requested.\n");
    exit(1);
}

$invalid = $request;
$invalid['extra'] = true;
operationsMustFail(static fn() => ProvisioningOperationsContract::validatePreflightRequest($invalid), 'extra request field');
$invalidResponse = [
    'ok' => true,
    'website_id' => 99,
    'domain' => 'example.com',
    'facts' => $facts,
];
operationsMustFail(static fn() => ProvisioningOperationsContract::validatePreflightResponse($invalidResponse, $request), 'mismatched website ID');

$blockedFacts = $facts;
$blockedFacts['resource_conflicts'] = ['spw_42'];
$blocked = ProvisioningOperationsContract::validatePreflightResponse([
    'ok' => true,
    'website_id' => 42,
    'domain' => 'example.com',
    'facts' => $blockedFacts,
], $request);
operationsMustFail(static fn() => ProvisioningWorkflow::build($blocked, true), 'blocked preflight');

fwrite(STDOUT, "Provisioning operations contract and workflow checks passed.\n");
