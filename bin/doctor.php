<?php
declare(strict_types=1);

/**
 * Read-only CLI preflight. This script never changes the OS or database.
 * Run from any directory with: php /path/to/spanel/bin/doctor.php
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

$failed = false;
$warnings = 0;

function doctorCheck(string $label, bool $ok, string $success, string $failure, bool $warning = false): void
{
    global $failed, $warnings;
    if ($ok) {
        fwrite(STDOUT, "[ OK ] {$label}: {$success}\n");
        return;
    }
    if ($warning) {
        $warnings++;
        fwrite(STDOUT, "[WARN] {$label}: {$failure}\n");
        return;
    }
    $failed = true;
    fwrite(STDOUT, "[FAIL] {$label}: {$failure}\n");
}

$root = dirname(__DIR__);
$requiredFiles = [
    'config/config.local.php',
    'config/config.example.php',
    'database/schema.sql',
    'bin/migrate.php',
    'bin/create-admin.php',
    'public/index.php',
];
doctorCheck('PHP version', PHP_VERSION_ID >= 80200, PHP_VERSION, 'PHP 8.2 or newer is required.');
doctorCheck('PDO MySQL extension', extension_loaded('pdo_mysql'), 'loaded', 'Install the php-mysql package.');
doctorCheck('mbstring extension', extension_loaded('mbstring'), 'loaded', 'Install the php-mbstring package.');
doctorCheck('Migration directory', is_dir($root . '/database/migrations') && is_readable($root . '/database/migrations'), 'readable', 'database/migrations is missing or unreadable.');

foreach ($requiredFiles as $relative) {
    doctorCheck($relative, is_file($root . '/' . $relative) && is_readable($root . '/' . $relative), 'present and readable', 'File is missing or unreadable.');
}

$configPath = $root . '/config/config.local.php';
$config = null;
if (is_file($configPath) && is_readable($configPath)) {
    try {
        $loaded = require $configPath;
        if (is_array($loaded)) {
            $config = $loaded;
        }
    } catch (Throwable) {
        $config = null;
    }
}
$configValid = is_array($config)
    && isset($config['database'])
    && is_array($config['database'])
    && is_string($config['database']['host'] ?? null)
    && $config['database']['host'] !== ''
    && is_int($config['database']['port'] ?? null)
    && ($config['database']['port'] > 0 && $config['database']['port'] <= 65535)
    && is_string($config['database']['name'] ?? null)
    && preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $config['database']['name']) === 1
    && is_string($config['database']['username'] ?? null)
    && $config['database']['username'] !== ''
    && is_string($config['database']['password'] ?? null)
    && $config['database']['password'] !== ''
    && $config['database']['password'] !== 'CHANGE_ME'
    && is_string($config['database']['charset'] ?? null)
    && $config['database']['charset'] === 'utf8mb4';
doctorCheck('Local configuration', $configValid, 'required database settings are present', 'Create config/config.local.php from config/config.example.php and set a non-placeholder password.');

if (PHP_OS_FAMILY !== 'Windows' && is_file($configPath)) {
    $permissions = fileperms($configPath);
    if ($permissions !== false) {
        doctorCheck('Configuration permissions', ($permissions & 0007) === 0, 'not accessible to other users', 'Restrict config/config.local.php permissions (for example chmod 640).', true);
    }
}

if (PHP_OS_FAMILY !== 'Windows') {
    $helperPath = '/usr/local/sbin/spanel-ops';
    $helperStat = @lstat($helperPath);
    $helperOwner = $helperStat !== false ? (int)($helperStat['uid'] ?? -1) : -1;
    $helperMode = $helperStat !== false ? (int)($helperStat['mode'] ?? 0) : 0;
    $helperReady = $helperStat !== false
        && is_file($helperPath)
        && is_executable($helperPath)
        && $helperOwner === 0
        && ($helperMode & 0022) === 0;
    doctorCheck('Restricted website provisioner', $helperReady, 'root-owned helper is present and not group/world writable', 'Installer must build/install /usr/local/sbin/spanel-ops with root ownership and safe permissions.', true);

    $keyPath = '/etc/spanel/database-secrets.key';
    $keyStat = @lstat($keyPath);
    $keyReady = $keyStat !== false
        && is_file($keyPath)
        && (int)($keyStat['uid'] ?? -1) === 0
        && (((int)($keyStat['mode'] ?? 0)) & 0077) === 0;
    $secretDir = @lstat('/var/lib/spanel/secrets');
    $secretDirReady = $secretDir !== false
        && is_dir('/var/lib/spanel/secrets')
        && (int)($secretDir['uid'] ?? -1) === 0
        && (((int)($secretDir['mode'] ?? 0)) & 0077) === 0;
    doctorCheck('Encrypted database secret store', $keyReady && $secretDirReady, 'root-only key and secret directory are present', 'Run the first-install setup on a clean Ubuntu VM; the panel account must not be able to read the key or secret files.', true);

    $timerReady = is_file('/etc/systemd/system/spanel-worker.timer');
    doctorCheck('Provisioning queue timer', $timerReady, 'systemd timer unit is installed', 'Install the spanel-worker.timer unit and enable it with systemd.', true);

    $brokerPath = '/usr/local/sbin/spanel-pma-credential-broker';
    $brokerStat = @lstat($brokerPath);
    $brokerReady = $brokerStat !== false
        && is_file($brokerPath)
        && is_executable($brokerPath)
        && (int)($brokerStat['uid'] ?? -1) === 0
        && (((int)($brokerStat['mode'] ?? 0)) & 0022) === 0;
    doctorCheck('phpMyAdmin credential broker', $brokerReady, 'root-owned fixed broker is installed', 'Install the restricted root-owned phpMyAdmin credential broker.', true);

    $phpMyAdminReady = is_file('/usr/share/phpmyadmin/index.php')
        && is_file('/etc/phpmyadmin/conf.d/99-spanel.php');
    doctorCheck('phpMyAdmin signon configuration', $phpMyAdminReady, 'phpMyAdmin and SPanel signon configuration are installed', 'Install phpMyAdmin and its SPanel signon configuration.', true);

    $panelSessionPath = '/var/lib/spanel/panel-sessions';
    $panelSessionStat = @lstat($panelSessionPath);
    $panelSessionReady = $panelSessionStat !== false
        && is_dir($panelSessionPath)
        && (int)($panelSessionStat['uid'] ?? -1) === (function_exists('posix_geteuid') ? posix_geteuid() : -2)
        && (((int)($panelSessionStat['mode'] ?? 0)) & 0077) === 0
        && is_readable($panelSessionPath);
    doctorCheck('SPanel session isolation', $panelSessionReady, 'panel session directory is private to the panel account', 'Check ownership and mode of /var/lib/spanel/panel-sessions.', true);

    $sessionDirPath = '/var/lib/spanel/phpmyadmin-sessions';
    $sessionDirStat = @lstat($sessionDirPath);
    $sessionDirReady = $sessionDirStat !== false
        && is_dir($sessionDirPath)
        && (int)($sessionDirStat['uid'] ?? -1) === 0
        && (((int)($sessionDirStat['mode'] ?? 0)) & 0007) === 0
        && !is_readable($sessionDirPath);
    doctorCheck('phpMyAdmin signon session isolation', $sessionDirReady, 'session store is root-owned and inaccessible to the panel account', 'Check the root:spanel-pma session directory and ensure the panel account cannot read it.', true);

    $phpVersion = PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    $pmaPoolPath = '/etc/php/' . $phpVersion . '/fpm/pool.d/spanel-pma.conf';
    doctorCheck('phpMyAdmin FPM pool', is_file($pmaPoolPath), 'dedicated non-panel PHP-FPM pool is installed', 'Install the spanel-pma PHP-FPM pool for the installed PHP version.', true);
}

$pdo = null;
if ($configValid && extension_loaded('pdo_mysql')) {
    try {
        $db = $config['database'];
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $db['host'], $db['port'], $db['name'], $db['charset']);
        $pdo = new PDO($dsn, $db['username'], $db['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 5,
        ]);
        doctorCheck('Database connection', true, 'connected using local configuration', 'not connected');
    } catch (Throwable) {
        doctorCheck('Database connection', false, '', 'Could not connect. Check the local DB settings, service and grants.');
    }
}

if ($pdo instanceof PDO) {
    try {
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
        $tableSet = array_fill_keys($tables, true);
        foreach (['admins', 'servers', 'websites', 'website_databases', 'schema_migrations'] as $table) {
            doctorCheck('Table ' . $table, isset($tableSet[$table]), 'present', 'Run the base schema and migration runner.');
        }

        if (isset($tableSet['schema_migrations'])) {
            $appliedRows = $pdo->query('SELECT migration_id, checksum FROM schema_migrations')->fetchAll();
            $applied = [];
            foreach ($appliedRows as $row) {
                $applied[(string)$row['migration_id']] = (string)$row['checksum'];
            }
            $files = glob($root . '/database/migrations/*.php') ?: [];
            sort($files, SORT_STRING);
            $pending = 0;
            foreach ($files as $file) {
                $id = basename($file, '.php');
                $checksum = hash_file('sha256', $file);
                if (!isset($applied[$id])) {
                    $pending++;
                } elseif (!hash_equals($applied[$id], $checksum)) {
                    doctorCheck('Migration checksum ' . $id, false, '', 'An applied migration differs from the database record.');
                }
            }
            foreach ($applied as $id => $_checksum) {
                if (!is_file($root . '/database/migrations/' . $id . '.php')) {
                    doctorCheck('Migration file ' . $id, false, '', 'An applied migration file is missing from the repository.');
                }
            }
            doctorCheck('Pending migrations', $pending === 0, 'none', $pending . ' migration(s) pending; review with php bin/migrate.php --status.', true);
        }
    } catch (Throwable) {
        doctorCheck('Database schema inspection', false, '', 'Connected, but could not inspect the schema. Check table privileges and migration state.');
    }
}

fwrite(STDOUT, "\nPreflight summary: " . ($failed ? 'FAIL' : 'PASS') . "; {$warnings} warning(s).\n");
if (!$failed) {
    fwrite(STDOUT, "This is a read-only preflight, not a full installation or security test.\n");
}
exit($failed ? 1 : 0);
