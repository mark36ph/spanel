#!/usr/bin/php
<?php
declare(strict_types=1);

/**
 * Root-only phpMyAdmin credential broker.
 *
 * Input is a bounded JSON request on stdin. This helper is intentionally
 * argument-free and returns only the one-time signon session ID and database
 * name. It never returns or logs a database password.
 */
const BROKER_MAX_INPUT = 4096;
const BROKER_KEY_PATH = '/etc/spanel/database-secrets.key';
const BROKER_SECRET_DIR = '/var/lib/spanel/secrets';
const BROKER_SIGNON_DIR = '/var/lib/spanel/phpmyadmin-sessions';
const BROKER_CONFIG_PATH = '/opt/spanel/config/config.local.php';
const BROKER_SESSION_NAME = 'spanel_pma_signon';

ini_set('display_errors', '0');
error_reporting(E_ALL);

function brokerReply(array $payload, int $exitCode = 0): never
{
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
    exit($exitCode);
}

function brokerFail(string $code): never
{
    brokerReply(['ok' => false, 'error_code' => $code], 1);
}

function brokerLstat(string $path): array
{
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if (!is_array($stat)) {
        throw new RuntimeException('path_unavailable');
    }
    return $stat;
}

function brokerSecureDirectory(string $path, int $forbiddenModeBits): array
{
    $stat = brokerLstat($path);
    if (($stat['mode'] & 0170000) !== 0040000
        || (($stat['mode'] & $forbiddenModeBits) !== 0)
        || (int)$stat['uid'] !== 0) {
        throw new RuntimeException('directory_permissions_invalid');
    }
    return $stat;
}

function brokerReadKey(): string
{
    brokerSecureDirectory('/etc/spanel', 0022);
    $stat = brokerLstat(BROKER_KEY_PATH);
    if (($stat['mode'] & 0170000) !== 0100000
        || (int)$stat['uid'] !== 0
        || (($stat['mode'] & 0077) !== 0)) {
        throw new RuntimeException('key_permissions_invalid');
    }
    $encoded = @file_get_contents(BROKER_KEY_PATH);
    if (!is_string($encoded)) {
        throw new RuntimeException('key_unavailable');
    }
    $key = hex2bin(trim($encoded));
    if (!is_string($key) || strlen($key) !== 32) {
        throw new RuntimeException('key_invalid');
    }
    return $key;
}

function brokerDecryptPassword(int $websiteId, string $key): string
{
    brokerSecureDirectory('/var/lib/spanel', 0022);
    brokerSecureDirectory(BROKER_SECRET_DIR, 0077);
    $path = BROKER_SECRET_DIR . '/website-db-' . $websiteId . '.enc';
    $stat = brokerLstat($path);
    if (($stat['mode'] & 0170000) !== 0100000
        || (int)$stat['uid'] !== 0
        || (($stat['mode'] & 0077) !== 0)) {
        throw new RuntimeException('secret_permissions_invalid');
    }
    if ((int)$stat['size'] > 4096) {
        throw new RuntimeException('secret_size_invalid');
    }
    $encoded = @file_get_contents($path);
    if (!is_string($encoded) || strlen($encoded) > 4096) {
        throw new RuntimeException('secret_unavailable');
    }
    $raw = base64_decode(trim($encoded), true);
    if (!is_string($raw) || strlen($raw) < 12 + 16) {
        throw new RuntimeException('secret_invalid');
    }
    $nonce = substr($raw, 0, 12);
    $tag = substr($raw, -16);
    $ciphertext = substr($raw, 12, -16);
    $password = openssl_decrypt(
        $ciphertext,
        'aes-256-gcm',
        $key,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag,
        'spanel-website-database-v1'
    );
    if (!is_string($password) || strlen($password) < 32) {
        throw new RuntimeException('secret_decryption_failed');
    }
    return $password;
}

function brokerValidateRequest(string $raw): array
{
    if ($raw === '' || strlen($raw) > BROKER_MAX_INPUT) {
        throw new RuntimeException('invalid_input');
    }
    $request = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
    if (!is_array($request) || array_is_list($request)) {
        throw new RuntimeException('invalid_request');
    }
    $expected = ['admin_id', 'database_id', 'operation', 'remote_addr', 'signon_session_id', 'token'];
    $keys = array_keys($request);
    sort($keys);
    sort($expected);
    if ($keys !== $expected
        || ($request['operation'] ?? null) !== 'database.handoff'
        || !is_int($request['admin_id'] ?? null) || $request['admin_id'] < 1
        || !is_int($request['database_id'] ?? null) || $request['database_id'] < 1
        || !is_string($request['token'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $request['token'])
        || !is_string($request['signon_session_id'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $request['signon_session_id'])) {
        throw new RuntimeException('invalid_request');
    }
    if ($request['remote_addr'] !== null
        && (!is_string($request['remote_addr']) || filter_var($request['remote_addr'], FILTER_VALIDATE_IP) === false)) {
        throw new RuntimeException('invalid_remote_address');
    }
    return $request;
}

function brokerCreateSignonSession(string $sessionId, string $username, string $password): string
{
    $directory = BROKER_SIGNON_DIR;
    $directoryStat = brokerSecureDirectory($directory, 0007);
    $sessionGroup = (int)$directoryStat['gid'];
    brokerSecureDirectory(BROKER_SECRET_DIR, 0077);
    if (($directoryStat['mode'] & 0020) === 0 || $sessionGroup < 1) {
        throw new RuntimeException('signon_directory_not_writable_by_group');
    }

    // Create the PHP session in the root-only secret directory first. Only
    // hard-link it into the group-writable phpMyAdmin session directory after
    // the owner/group/mode are correct, avoiding symlink races in that directory.
    $privateFile = BROKER_SECRET_DIR . '/sess_' . $sessionId;
    $publicFile = $directory . '/sess_' . $sessionId;
    if (file_exists($privateFile) || is_link($privateFile) || file_exists($publicFile) || is_link($publicFile)) {
        throw new RuntimeException('signon_session_collision');
    }

    ini_set('session.use_cookies', '0');
    ini_set('session.use_only_cookies', '0');
    ini_set('session.use_strict_mode', '0');
    ini_set('session.gc_maxlifetime', '1800');
    session_name(BROKER_SESSION_NAME);
    session_save_path(BROKER_SECRET_DIR);
    session_id($sessionId);
    if (!session_start()) {
        throw new RuntimeException('signon_session_start_failed');
    }

    $_SESSION = [
        'PMA_single_signon_user' => $username,
        'PMA_single_signon_password' => $password,
        'PMA_single_signon_host' => 'localhost',
        'PMA_single_signon_port' => 3306,
        'PMA_single_signon_HMAC_secret' => bin2hex(random_bytes(32)),
    ];
    session_write_close();

    $published = false;
    try {
        $stat = brokerLstat($privateFile);
        if (($stat['mode'] & 0170000) !== 0100000
            || (int)$stat['uid'] !== 0
            || (($stat['mode'] & 0077) !== 0)
            || !@chgrp($privateFile, $sessionGroup)
            || !@chmod($privateFile, 0660)) {
            throw new RuntimeException('signon_session_permissions_failed');
        }
        $finalStat = brokerLstat($privateFile);
        if ((int)$finalStat['uid'] !== 0
            || (int)$finalStat['gid'] !== $sessionGroup
            || (($finalStat['mode'] & 0777) !== 0660)) {
            throw new RuntimeException('signon_session_permissions_failed');
        }
        if (file_exists($publicFile) || is_link($publicFile) || !@link($privateFile, $publicFile)) {
            throw new RuntimeException('signon_session_publish_failed');
        }
        $published = true;
        @unlink($privateFile);
        return $publicFile;
    } catch (Throwable $e) {
        @unlink($privateFile);
        if ($published) {
            @unlink($publicFile);
        }
        throw $e;
    }
}

if (PHP_SAPI !== 'cli' || !function_exists('posix_geteuid') || posix_geteuid() !== 0) {
    brokerFail('must_run_as_root_cli');
}
if ($argc !== 1) {
    brokerFail('arguments_not_supported');
}

$sessionFile = null;
try {
    $raw = stream_get_contents(STDIN, BROKER_MAX_INPUT + 1);
    if (!is_string($raw) || strlen($raw) > BROKER_MAX_INPUT) {
        throw new RuntimeException('invalid_input');
    }
    $request = brokerValidateRequest($raw);
    brokerSecureDirectory('/opt/spanel', 0022);
    brokerSecureDirectory('/opt/spanel/config', 0022);
    $configStat = brokerLstat(BROKER_CONFIG_PATH);
    if (($configStat['mode'] & 0170000) !== 0100000
        || (int)$configStat['uid'] !== 0
        || (($configStat['mode'] & 0022) !== 0)) {
        throw new RuntimeException('panel_config_permissions_invalid');
    }

    require_once '/opt/spanel/app/PhpMyAdminHandoff.php';
    $config = require BROKER_CONFIG_PATH;
    if (!is_array($config) || !is_array($config['database'] ?? null)) {
        throw new RuntimeException('panel_config_invalid');
    }
    $db = $config['database'];
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']);
    $pdo = new PDO($dsn, $db['username'], $db['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);

    $consumed = PhpMyAdminHandoff::consume($pdo, $request['token'], $request['admin_id']);
    if (!is_array($consumed)
        || (int)$consumed['admin_id'] !== $request['admin_id']
        || (int)$consumed['website_database_id'] !== $request['database_id']
        || $consumed['remote_addr'] !== $request['remote_addr']) {
        throw new RuntimeException('handoff_invalid_or_expired');
    }

    $stmt = $pdo->prepare(
        'SELECT d.id, d.website_id, d.server_id, d.database_name, d.database_user, d.status AS database_status, d.secret_ref,
                w.status AS website_status, w.server_id AS website_server_id, s.is_local, a.is_active
         FROM website_databases d
         INNER JOIN websites w ON w.id = d.website_id
         INNER JOIN servers s ON s.id = d.server_id
         INNER JOIN admins a ON a.id = :admin_id
         WHERE d.id = :database_id
         LIMIT 1'
    );
    $stmt->execute(['admin_id' => $request['admin_id'], 'database_id' => $request['database_id']]);
    $row = $stmt->fetch();
    if (!$row
        || (int)$row['is_active'] !== 1
        || (int)$row['is_local'] !== 1
        || (int)$row['server_id'] !== (int)$row['website_server_id']
        || (int)$row['website_id'] < 1
        || $row['website_status'] !== 'active'
        || $row['database_status'] !== 'active'
        || $row['secret_ref'] !== 'website-db:' . (int)$row['website_id']
        || $row['database_name'] !== 'spw_' . (int)$row['website_id']
        || $row['database_user'] !== 'spu_' . (int)$row['website_id']) {
        throw new RuntimeException('database_not_authorized');
    }

    $key = brokerReadKey();
    $password = brokerDecryptPassword((int)$row['website_id'], $key);
    $testDsn = sprintf('mysql:host=localhost;port=3306;dbname=%s;charset=utf8mb4', $row['database_name']);
    $testConnection = new PDO($testDsn, (string)$row['database_user'], $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]);
    $testConnection->query('SELECT 1')->fetchColumn();
    unset($testConnection);
    $sessionId = $request['signon_session_id'];
    $sessionFile = brokerCreateSignonSession($sessionId, (string)$row['database_user'], $password);
    unset($password, $key);

    $audit = $pdo->prepare(
        "INSERT INTO audit_events (admin_id, event_type, target_type, target_id, details_json, remote_addr)
         VALUES (:admin_id, 'phpmyadmin.handoff.completed', 'website_database', :target_id, :details, :remote_addr)"
    );
    $audit->execute([
        'admin_id' => $request['admin_id'],
        'target_id' => (string)$request['database_id'],
        'details' => json_encode([
            'website_id' => (int)$row['website_id'],
            'database_name' => (string)$row['database_name'],
            'server_id' => (int)$row['server_id'],
        ], JSON_THROW_ON_ERROR),
        'remote_addr' => $request['remote_addr'],
    ]);

    brokerReply([
        'ok' => true,
        'session_id' => $sessionId,
        'database_name' => (string)$row['database_name'],
    ]);
} catch (Throwable $e) {
    if (is_string($sessionFile) && is_file($sessionFile) && !is_link($sessionFile)) {
        @unlink($sessionFile);
    }
    // Log only a generic code. Never log the request, token, SQL values or credentials.
    error_log('SPanel phpMyAdmin credential broker failed closed.');
    brokerFail('handoff_unavailable');
}
