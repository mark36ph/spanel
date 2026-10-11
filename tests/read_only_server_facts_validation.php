<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/app/ReadOnlyServerFacts.php';
require_once dirname(__DIR__) . '/app/ProvisioningPreflight.php';

function factsMustFail(callable $fn, string $case): void {
    try { $fn(); } catch (InvalidArgumentException) { return; }
    fwrite(STDERR, "Expected read-only facts validation failure: {$case}\n"); exit(1);
}
$root = sys_get_temp_dir() . '/spanel-facts-' . bin2hex(random_bytes(5));
$make = static function (string $relative) use ($root): void {
    $p = $root . $relative;
    if (!is_dir(dirname($p))) { mkdir(dirname($p), 0700, true); }
    file_put_contents($p, '');
};
mkdir($root, 0700);
try {
    $make('/usr/bin/php8.3'); chmod($root . '/usr/bin/php8.3', 0700);
    mkdir($root . '/etc/php/8.3/fpm', 0700, true);
    mkdir($root . '/var/www', 0700, true);
    $make('/etc/passwd');
    file_put_contents($root . '/etc/passwd', "root:x:0:0:root:/root:/bin/bash\nspw7:x:1007:1007::/var/www:/usr/sbin/nologin\n");
    $request = ['website_id' => 7, 'domain' => 'example.com', 'required_disk_bytes' => 1024];
    $facts = ReadOnlyServerFacts::collect($request, null, $root);
    if ($facts['installed_php_versions'] !== ['8.3'] || $facts['php_fpm_versions'] !== ['8.3']
        || $facts['nginx_config_valid'] !== null || $facts['dns_matches'] !== null
        || $facts['database_available'] !== false || !in_array('user:spw7', $facts['resource_conflicts'], true)
        || $facts['required_disk_bytes'] !== 1024) {
        fwrite(STDERR, "Read-only facts did not match isolated fixture.\n"); exit(1);
    }
    $evaluation = ProvisioningPreflight::evaluate($facts, null, false);
    if ($evaluation['ready'] || $evaluation['selected_php_version'] !== '8.3') {
        fwrite(STDERR, "Expected PHP 8.3 selection while an existing site user blocks readiness.\n"); exit(1);
    }
    factsMustFail(static fn() => ReadOnlyServerFacts::collect(['website_id'=>0,'domain'=>'example.com','required_disk_bytes'=>1], null, $root), 'invalid website ID');
    factsMustFail(static fn() => ReadOnlyServerFacts::collect(['website_id'=>7,'domain'=>'example.com','required_disk_bytes'=>0], null, $root), 'invalid disk requirement');
    factsMustFail(static fn() => ReadOnlyServerFacts::collect(['website_id'=>7,'domain'=>'EXAMPLE.COM','required_disk_bytes'=>1], null, $root), 'non-normalized domain');
} finally {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $item) { $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
    rmdir($root);
}
fwrite(STDOUT, "Read-only server facts validation checks passed.\n");
