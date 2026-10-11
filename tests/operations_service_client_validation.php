<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/OperationsServiceClient.php';

function clientMustReject(callable $fn, string $case): void
{
    try {
        $fn();
    } catch (InvalidArgumentException) {
        return;
    }
    fwrite(STDERR, "Expected operations client validation failure: {$case}\n");
    exit(1);
}

$request = [
    'operation' => 'website.preflight',
    'website_id' => 1,
    'domain' => 'example.com',
    'php_version' => null,
    'create_database' => false,
    'required_disk_bytes' => 1000000,
];

if (OperationsServiceClient::SOCKET_URI !== 'unix:///run/spanel-ops/operations.sock') {
    fwrite(STDERR, "Operations client socket must remain fixed to the local service.\n");
    exit(1);
}

$client = new OperationsServiceClient();
$invalid = $request;
$invalid['command'] = 'not-allowed';
clientMustReject(static fn() => $client->preflight($invalid), 'reject extra field before connecting');

$invalid = $request;
$invalid['website_id'] = '1';
clientMustReject(static fn() => $client->preflight($invalid), 'reject wrong identifier type before connecting');

fwrite(STDOUT, "Operations service client validates requests before using its fixed local socket.\n");
