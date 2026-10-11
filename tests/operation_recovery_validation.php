<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/OperationRecovery.php';

foreach ([5, 15, 60, 1440] as $valid) {
    if (OperationRecovery::validateStaleMinutes($valid) !== $valid) {
        fwrite(STDERR, "Valid stale-minute setting changed unexpectedly.\n");
        exit(1);
    }
}

foreach ([-1, 0, 1, 4, 1441, 9999] as $invalid) {
    try {
        OperationRecovery::validateStaleMinutes($invalid);
    } catch (InvalidArgumentException) {
        continue;
    }
    fwrite(STDERR, "Invalid stale-minute setting was accepted: {$invalid}\n");
    exit(1);
}

fwrite(STDOUT, "Operation recovery input checks passed.\n");
