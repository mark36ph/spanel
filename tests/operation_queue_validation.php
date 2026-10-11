<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/OperationQueue.php';

function expectInvalidPayload(callable $callback, string $case): void
{
    try {
        $callback();
    } catch (InvalidArgumentException) {
        return;
    }
    fwrite(STDERR, "Expected validation failure: {$case}\n");
    exit(1);
}

$valid = OperationQueue::validatePayload('website.provision', [
    'create_database_requested' => true,
    'php_version_requested' => '8.3',
]);
if ($valid !== ['create_database_requested' => true, 'php_version_requested' => '8.3']) {
    fwrite(STDERR, "Valid payload was not normalized as expected.\n");
    exit(1);
}

$validNull = OperationQueue::validatePayload('website.provision', [
    'create_database_requested' => false,
    'php_version_requested' => null,
]);
if ($validNull['php_version_requested'] !== null || $validNull['create_database_requested'] !== false) {
    fwrite(STDERR, "Nullable PHP version payload failed.\n");
    exit(1);
}

expectInvalidPayload(
    static fn() => OperationQueue::validatePayload('system.shell', ['command' => 'id']),
    'unknown operation'
);
expectInvalidPayload(
    static fn() => OperationQueue::validatePayload('website.provision', [
        'create_database_requested' => true,
        'php_version_requested' => '8.3',
        'command' => 'id',
    ]),
    'extra command field'
);
expectInvalidPayload(
    static fn() => OperationQueue::validatePayload('website.provision', [
        'create_database_requested' => 'yes',
        'php_version_requested' => '8.3',
    ]),
    'non-boolean database flag'
);
expectInvalidPayload(
    static fn() => OperationQueue::validatePayload('website.provision', [
        'create_database_requested' => true,
        'php_version_requested' => '8.4',
    ]),
    'unsupported PHP version'
);

fwrite(STDOUT, "OperationQueue validation checks passed.\n");
