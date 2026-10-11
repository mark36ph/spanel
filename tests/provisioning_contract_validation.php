<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/ProvisioningContract.php';

function expectContractFailure(callable $callback, string $case): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    fwrite(STDERR, "Expected provisioning contract rejection: {$case}\n");
    exit(1);
}

$request = [
    'operation' => 'website.provision',
    'website_id' => 42,
    'domain' => 'example.com',
    'php_version' => '8.3',
    'create_database' => true,
];
$normalized = ProvisioningContract::validateRequest($request);
if ($normalized !== $request) {
    fwrite(STDERR, "Valid provisioning request was changed unexpectedly.\n");
    exit(1);
}

$response = [
    'ok' => true,
    'website_id' => 42,
    'domain' => 'example.com',
    'php_version' => '8.3',
    'database_created' => true,
];
if (ProvisioningContract::validateResponse($response, $request) !== $response) {
    fwrite(STDERR, "Valid provisioning response failed.\n");
    exit(1);
}

$autoRequest = $request;
$autoRequest['php_version'] = null;
$autoResponse = $response;
$autoResponse['php_version'] = '8.2';
if (ProvisioningContract::validateResponse($autoResponse, $autoRequest)['php_version'] !== '8.2') {
    fwrite(STDERR, "Automatic PHP runtime selection failed.\n");
    exit(1);
}

expectContractFailure(static function () use ($request): void {
    $bad = $request;
    $bad['command'] = 'id';
    ProvisioningContract::validateRequest($bad);
}, 'extra command field');

expectContractFailure(static function () use ($request): void {
    $bad = $request;
    $bad['website_id'] = '42';
    ProvisioningContract::validateRequest($bad);
}, 'string website ID');

expectContractFailure(static function () use ($request): void {
    $bad = $request;
    $bad['domain'] = 'Example.com';
    ProvisioningContract::validateRequest($bad);
}, 'non-normalized domain');

expectContractFailure(static function () use ($request, $response): void {
    $bad = $response;
    $bad['domain'] = 'other.example';
    ProvisioningContract::validateResponse($bad, $request);
}, 'wrong returned domain');

expectContractFailure(static function () use ($request, $response): void {
    $bad = $response;
    $bad['database_created'] = false;
    ProvisioningContract::validateResponse($bad, $request);
}, 'database result mismatch');

expectContractFailure(static function () use ($request, $response): void {
    $bad = $response;
    $bad['php_version'] = '8.4';
    ProvisioningContract::validateResponse($bad, $request);
}, 'unsupported selected PHP version');

expectContractFailure(static function () use ($request, $response): void {
    $bad = $response;
    $bad['shell_output'] = 'unexpected';
    ProvisioningContract::validateResponse($bad, $request);
}, 'extra response field');

expectContractFailure(static function () use ($request): void {
    $bad = $request;
    $bad['operation'] = 'system.shell';
    ProvisioningContract::validateRequest($bad);
}, 'unsupported operation');

expectContractFailure(static function () use ($request): void {
    $bad = $request;
    $bad['create_database'] = 'true';
    ProvisioningContract::validateRequest($bad);
}, 'string database flag');

expectContractFailure(static function () use ($request): void {
    $bad = $request;
    $bad['domain'] = 'example.com/path';
    ProvisioningContract::validateRequest($bad);
}, 'domain containing a path');

expectContractFailure(static function () use ($request, $response): void {
    $bad = $response;
    $bad['website_id'] = 43;
    ProvisioningContract::validateResponse($bad, $request);
}, 'wrong returned website ID');

expectContractFailure(static function () use ($request, $response): void {
    $bad = $response;
    $bad['ok'] = false;
    ProvisioningContract::validateResponse($bad, $request);
}, 'helper did not confirm success');

expectContractFailure(static function () use ($request, $response): void {
    $bad = $response;
    $bad['php_version'] = '8.2';
    ProvisioningContract::validateResponse($bad, $request);
}, 'selected PHP version differs from explicit request');

fwrite(STDOUT, "Provisioning contract validation checks passed.\n");
