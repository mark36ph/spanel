<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/OperationsServiceProtocol.php';

function protocolMustFail(callable $fn, string $case): void
{
    try {
        $fn();
    } catch (InvalidArgumentException) {
        return;
    }
    fwrite(STDERR, "Expected operations protocol rejection: {$case}\n");
    exit(1);
}

$provisionRequest = [
    'operation' => 'website.provision',
    'website_id' => 42,
    'domain' => 'example.com',
    'php_version' => '8.3',
    'create_database' => true,
];
$encoded = OperationsServiceProtocol::encodeProvisionRequest($provisionRequest);
if (!str_ends_with($encoded, "\n") || substr_count($encoded, "\n") !== 1) {
    fwrite(STDERR, "Expected exactly one JSON-line request frame.\n");
    exit(1);
}
$provisionResponse = [
    'ok' => true,
    'website_id' => 42,
    'domain' => 'example.com',
    'php_version' => '8.3',
    'database_created' => true,
];
$decoded = OperationsServiceProtocol::decodeProvisionResponse(
    json_encode($provisionResponse, JSON_THROW_ON_ERROR) . "\n",
    $provisionRequest
);
if ($decoded['website_id'] !== 42 || $decoded['database_created'] !== true) {
    fwrite(STDERR, "Expected validated provisioning response.\n");
    exit(1);
}

$preflightRequest = [
    'operation' => 'website.preflight',
    'website_id' => 42,
    'domain' => 'example.com',
    'php_version' => null,
    'create_database' => true,
    'required_disk_bytes' => 1000000,
];
OperationsServiceProtocol::encodePreflightRequest($preflightRequest);

protocolMustFail(
    static fn() => OperationsServiceProtocol::encodeProvisionRequest($provisionRequest + ['command' => 'id']),
    'extra request field'
);
protocolMustFail(
    static fn() => OperationsServiceProtocol::decodeProvisionResponse('{"ok":true}',
        $provisionRequest),
    'missing frame terminator'
);
protocolMustFail(
    static fn() => OperationsServiceProtocol::decodeProvisionResponse("{}\n{}\n",
        $provisionRequest),
    'multiple frames'
);
protocolMustFail(
    static fn() => OperationsServiceProtocol::decodeProvisionResponse(str_repeat('x', 16384) . "\n",
        $provisionRequest),
    'oversized response'
);
$mismatch = $provisionResponse;
$mismatch['website_id'] = 99;
protocolMustFail(
    static fn() => OperationsServiceProtocol::decodeProvisionResponse(
        json_encode($mismatch, JSON_THROW_ON_ERROR) . "\n",
        $provisionRequest
    ),
    'response identity mismatch'
);
protocolMustFail(
    static fn() => OperationsServiceProtocol::decodeProvisionResponse("{bad json}\n",
        $provisionRequest),
    'malformed JSON'
);

fwrite(STDOUT, "Operations service protocol validation checks passed.\n");
