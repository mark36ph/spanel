<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/ProvisioningPlan.php';

function planMustFail(callable $fn, string $case): void
{
    try {
        $fn();
    } catch (InvalidArgumentException) {
        return;
    }
    fwrite(STDERR, "Expected provisioning plan rejection: {$case}\n");
    exit(1);
}

$plan = ProvisioningPlan::build(42, 'example.com', '8.3');
$expected = [
    'linux_user' => 'spw42',
    'pool_name' => 'spanel-site-42',
    'document_root' => '/var/www/spanel/sites/42/public',
    'php_fpm_socket' => '/run/php/spanel-site-42.sock',
    'nginx_site_path' => '/etc/nginx/sites-available/spanel-site-42',
    'php_fpm_pool_path' => '/etc/php/8.3/fpm/pool.d/spanel-site-42.conf',
    'database_name' => 'spw_42',
    'database_user' => 'spu_42',
];
foreach ($expected as $key => $value) {
    if (($plan[$key] ?? null) !== $value) {
        fwrite(STDERR, "Unexpected deterministic resource value for {$key}.\n");
        exit(1);
    }
}
if (!str_contains($plan['nginx_config'], 'server_name example.com;')
    || !str_contains($plan['nginx_config'], 'try_files $uri $uri/ /index.php?$query_string;')
    || !str_contains($plan['nginx_config'], 'location ~ /\\.(?!well-known(?:/|$)).* {')
    || strpos($plan['nginx_config'], 'location ~ /\\.(?!well-known(?:/|$)).* {')
        > strpos($plan['nginx_config'], 'location ~ \\.php$ {')
    || !str_contains($plan['nginx_config'], 'deny all;')
    || !str_contains($plan['php_fpm_pool_config'], 'user = spw42')
    || !str_contains($plan['php_fpm_pool_config'], 'security.limit_extensions = .php')) {
    fwrite(STDERR, "Generated configuration is missing a required safe directive.\n");
    exit(1);
}

planMustFail(static fn() => ProvisioningPlan::build(0, 'example.com', '8.3'), 'zero website ID');
planMustFail(static fn() => ProvisioningPlan::build(42, 'Example.com', '8.3'), 'non-normalized domain');
planMustFail(static fn() => ProvisioningPlan::build(42, 'example.com; include /tmp/x;', '8.3'), 'directive injection in domain');
planMustFail(static fn() => ProvisioningPlan::build(42, 'example.com', '8.4'), 'unsupported PHP version');

fwrite(STDOUT, "Provisioning plan validation checks passed.\n");
