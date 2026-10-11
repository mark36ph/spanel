<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/OperationsServiceClient.php';

$request = [
    'operation' => 'website.preflight',
    'website_id' => 1,
    'domain' => 'example.com',
    'php_version' => null,
    'create_database' => false,
    'required_disk_bytes' => 1000000,
];

$client = new OperationsServiceClient();
try {
    $client->preflight($request);
    fwrite(STDERR, "Expected unavailable service to fail closed in the test environment.\n");
    exit(1);
} catch (RuntimeException $e) {
    if (!str_contains($e->getMessage(), 'service is unavailable')) {
        fwrite(STDERR, "Unexpected service failure: " . $e->getMessage() . "\n");
        exit(1);
    }
}

fwrite(STDOUT, "Operations service client fails closed when its fixed socket is unavailable.\n");
