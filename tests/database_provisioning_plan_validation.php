<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/DatabaseProvisioningPlan.php';

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }
}

$plan = DatabaseProvisioningPlan::build(3, 42);
expect($plan['server_id'] === 3 && $plan['website_id'] === 42, 'Ownership IDs were not preserved.');
expect($plan['database_name'] === 'spw_42', 'Database name is not deterministic.');
expect($plan['database_user'] === 'spu_42', 'Database username is not deterministic.');
expect($plan['database_host'] === 'localhost', 'Database user host must be local-only.');
expect($plan['grant_scope'] === 'spw_42.*', 'Grant scope must be limited to this database.');
expect($plan['secret_ref'] === 'website-db:42', 'Secret reference is not stable and scoped.');
expect($plan['password_included'] === false, 'A password must never be present in the plan.');
expect($plan['execution_enabled'] === false, 'The plan must not enable execution.');
expect(!in_array('ALL PRIVILEGES', $plan['privileges'], true), 'Global privileges must not be granted.');
expect(!in_array('GRANT OPTION', $plan['privileges'], true), 'GRANT OPTION must not be granted.');

foreach ([0, -1] as $invalidId) {
    try {
        DatabaseProvisioningPlan::build($invalidId, 42);
        throw new RuntimeException('Invalid server ID was accepted.');
    } catch (InvalidArgumentException) {
        // Expected.
    }
    try {
        DatabaseProvisioningPlan::build(3, $invalidId);
        throw new RuntimeException('Invalid website ID was accepted.');
    } catch (InvalidArgumentException) {
        // Expected.
    }
}

fwrite(STDOUT, "Database provisioning plan validation passed.\n");
