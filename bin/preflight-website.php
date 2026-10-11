<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/app/Database.php';
require_once dirname(__DIR__) . '/app/DomainName.php';
require_once dirname(__DIR__) . '/app/ProvisioningOperationsContract.php';
require_once dirname(__DIR__) . '/app/ReadOnlyServerFacts.php';

function usage(): never
{
    fwrite(STDERR, "Usage: php bin/preflight-website.php WEBSITE_ID [--php=8.2|8.3] [--database] [--disk-bytes=BYTES]\n");
    exit(2);
}

$args = $argv;
array_shift($args);
if ($args === [] || !preg_match('/^[1-9][0-9]{0,9}$/D', $args[0])) {
    usage();
}
$websiteId = (int) array_shift($args);
$phpVersion = null;
$createDatabase = false;
$requiredDiskBytes = 100_000_000;
foreach ($args as $arg) {
    if (str_starts_with($arg, '--php=')) {
        if ($phpVersion !== null || !in_array(substr($arg, 6), ['8.2', '8.3'], true)) {
            usage();
        }
        $phpVersion = substr($arg, 6);
    } elseif ($arg === '--database') {
        if ($createDatabase) { usage(); }
        $createDatabase = true;
    } elseif (str_starts_with($arg, '--disk-bytes=')) {
        $value = substr($arg, 13);
        if (!preg_match('/^[1-9][0-9]{0,14}$/D', $value) || (float) $value > PHP_INT_MAX) {
            usage();
        }
        $requiredDiskBytes = (int) $value;
    } else {
        usage();
    }
}

try {
    $pdo = Database::connection();
    $stmt = $pdo->prepare('SELECT id, domain FROM websites WHERE id = :id LIMIT 1');
    $stmt->execute(['id' => $websiteId]);
    $website = $stmt->fetch();
    if (!is_array($website) || !is_string($website['domain'] ?? null)
        || DomainName::normalize($website['domain']) !== $website['domain']) {
        fwrite(STDERR, "Website not found or its domain is invalid.\n");
        exit(1);
    }

    $request = ProvisioningOperationsContract::validatePreflightRequest([
        'operation' => 'website.preflight',
        'website_id' => $websiteId,
        'domain' => $website['domain'],
        'php_version' => $phpVersion,
        'create_database' => $createDatabase,
        'required_disk_bytes' => $requiredDiskBytes,
    ]);
    $facts = ReadOnlyServerFacts::collect($request, $pdo);
    $evaluation = ProvisioningPreflight::evaluate($facts, $phpVersion, $createDatabase);
    $output = [
        'mode' => 'read_only_local_preflight',
        'website_id' => $websiteId,
        'domain' => $website['domain'],
        'facts' => $facts,
        'evaluation' => $evaluation,
        'limitations' => [
            'nginx_config_valid' => 'not checked; this command never executes system commands',
            'dns_matches' => 'unknown; target server IP is not configured in this request',
            'system_changes' => 'none; this command is read-only',
        ],
    ];
    fwrite(STDOUT, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit($evaluation['ready'] ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, "Preflight failed safely: " . mb_substr($e->getMessage(), 0, 240) . "\n");
    exit(1);
}
