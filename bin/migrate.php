<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

require_once dirname(__DIR__) . '/app/Database.php';

const MIGRATIONS_DIR = __DIR__ . '/../database/migrations';
const MIGRATION_LOCK = 'spanel_schema_migrations';

function usage(): never
{
    fwrite(STDOUT, "Usage: php bin/migrate.php [--status] [--dry-run]\n");
    exit(0);
}

$args = array_slice($argv, 1);
$allowed = ['--status', '--dry-run'];
foreach ($args as $arg) {
    if (!in_array($arg, $allowed, true)) {
        fwrite(STDERR, "Unknown option: {$arg}\n");
        usage();
    }
}
if (count($args) > 1 || (in_array('--status', $args, true) && in_array('--dry-run', $args, true))) {
    fwrite(STDERR, "Choose at most one option.\n");
    usage();
}
$mode = $args[0] ?? 'apply';

$pdo = null;
$locked = false;
try {
    if (PHP_VERSION_ID < 80200) {
        throw new RuntimeException('PHP 8.2 or newer is required.');
    }
    if (!is_dir(MIGRATIONS_DIR) || !is_readable(MIGRATIONS_DIR)) {
        throw new RuntimeException('Migration directory is missing or unreadable.');
    }

    $files = glob(MIGRATIONS_DIR . '/*.php') ?: [];
    sort($files, SORT_STRING);
    $migrations = [];
    foreach ($files as $file) {
        $id = basename($file, '.php');
        if (!preg_match('/^\d{12}_[a-z0-9_]+$/', $id)) {
            throw new RuntimeException('Invalid migration filename: ' . basename($file));
        }
        $callable = require $file;
        if (!is_callable($callable)) {
            throw new RuntimeException("Migration {$id} must return a callable accepting PDO.");
        }
        $migrations[$id] = ['file' => $file, 'checksum' => hash_file('sha256', $file), 'run' => $callable];
    }

    $pdo = Database::connection();
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        migration_id VARCHAR(190) NOT NULL,
        checksum CHAR(64) NOT NULL,
        applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (migration_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if ($mode !== '--dry-run') {
        $stmt = $pdo->prepare('SELECT GET_LOCK(:lock_name, 10)');
        $stmt->execute(['lock_name' => MIGRATION_LOCK]);
        if ((int)$stmt->fetchColumn() !== 1) {
            throw new RuntimeException('Could not acquire migration lock; another migration may be running.');
        }
        $locked = true;
    }

    $appliedRows = $pdo->query('SELECT migration_id, checksum, applied_at FROM schema_migrations')->fetchAll();
    $applied = [];
    foreach ($appliedRows as $row) {
        $applied[$row['migration_id']] = $row;
    }

    foreach ($applied as $id => $row) {
        if (!isset($migrations[$id])) {
            throw new RuntimeException("Applied migration {$id} is missing from the repository. Restore the immutable migration file before continuing.");
        }
        if (!hash_equals($row['checksum'], $migrations[$id]['checksum'])) {
            throw new RuntimeException("Checksum mismatch for applied migration {$id}; refusing to continue.");
        }
    }

    if ($mode === '--status') {
        foreach ($migrations as $id => $migration) {
            if (isset($applied[$id])) {
                fwrite(STDOUT, "[applied] {$id} ({$applied[$id]['applied_at']})\n");
            } else {
                fwrite(STDOUT, "[pending] {$id}\n");
            }
        }
        if (!$migrations) {
            fwrite(STDOUT, "No migration files found.\n");
        }
        exit(0);
    }

    $pending = array_filter($migrations, static fn(array $m, string $id): bool => !isset($applied[$id]), ARRAY_FILTER_USE_BOTH);
    if (!$pending) {
        fwrite(STDOUT, "Database schema is up to date.\n");
        exit(0);
    }
    if ($mode === '--dry-run') {
        foreach ($pending as $id => $_) {
            fwrite(STDOUT, "[would apply] {$id}\n");
        }
        exit(0);
    }

    foreach ($pending as $id => $migration) {
        fwrite(STDOUT, "Applying {$id}...\n");
        try {
            ($migration['run'])($pdo);
            // MariaDB DDL may implicitly commit. Record only after migration work succeeds.
            $record = $pdo->prepare('INSERT INTO schema_migrations (migration_id, checksum) VALUES (:id, :checksum)');
            $record->execute(['id' => $id, 'checksum' => $migration['checksum']]);
        } catch (Throwable $e) {
            throw new RuntimeException("Migration {$id} failed. It was not recorded as applied. Inspect the schema/data and follow the migration recovery notes before retrying. Details: " . $e->getMessage(), 0, $e);
        }
        fwrite(STDOUT, "Applied {$id}.\n");
    }
    fwrite(STDOUT, "Database migrations complete.\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Migration error: ' . $e->getMessage() . "\n");
    exitCode(1);
} finally {
    if ($locked && $pdo instanceof PDO) {
        try {
            $stmt = $pdo->prepare('SELECT RELEASE_LOCK(:lock_name)');
            $stmt->execute(['lock_name' => MIGRATION_LOCK]);
        } catch (Throwable) {
            // The database connection will release its advisory lock on disconnect.
        }
    }
}

function exitCode(int $code): never
{
    exit($code);
}
