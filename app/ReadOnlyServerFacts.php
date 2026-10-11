<?php
declare(strict_types=1);

require_once __DIR__ . '/DomainName.php';

/** Read-only best-effort facts; never shells out or changes host state. */
final class ReadOnlyServerFacts
{
    private const SUPPORTED_PHP = ['8.2', '8.3'];

    public static function collect(array $request, ?PDO $pdo = null, string $root = '/'): array
    {
        if (!is_int($request['required_disk_bytes'] ?? null) || $request['required_disk_bytes'] < 1
            || !is_int($request['website_id'] ?? null) || $request['website_id'] < 1
            || !is_string($request['domain'] ?? null)
            || DomainName::normalize($request['domain']) !== $request['domain']) {
            throw new InvalidArgumentException('Read-only preflight input is invalid.');
        }

        $root = rtrim($root, '/') ?: '/';
        $path = static fn(string $p): string => $root === '/' ? $p : $root . $p;
        $installed = [];
        $fpm = [];
        foreach (self::SUPPORTED_PHP as $version) {
            if (is_file($path('/usr/bin/php' . $version)) && is_executable($path('/usr/bin/php' . $version))) {
                $installed[] = $version;
            }
            if (is_file($path('/usr/sbin/php-fpm' . $version))
                || is_file($path('/usr/bin/php-fpm' . $version))
                || is_dir($path('/etc/php/' . $version . '/fpm'))) {
                $fpm[] = $version;
            }
        }

        $diskPath = is_dir($path('/var/www')) ? $path('/var/www') : $path('/var');
        $free = @disk_free_space($diskPath);
        $free = (is_float($free) || is_int($free)) ? max(0, (int) min($free, PHP_INT_MAX)) : 0;

        $id = (string) $request['website_id'];
        $conflicts = [];
        foreach ([
            '/var/www/spanel/sites/' . $id,
            '/etc/nginx/sites-available/spanel-site-' . $id,
            '/etc/nginx/sites-enabled/spanel-site-' . $id,
            '/etc/php/8.2/fpm/pool.d/spanel-site-' . $id . '.conf',
            '/etc/php/8.3/fpm/pool.d/spanel-site-' . $id . '.conf',
        ] as $candidate) {
            if (file_exists($path($candidate)) || is_link($path($candidate))) {
                $conflicts[] = 'path:' . $candidate;
            }
        }

        $user = 'spw' . $id;
        $passwdPath = $path('/etc/passwd');
        if (is_readable($passwdPath)) {
            foreach (@file($passwdPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                if (explode(':', $line, 2)[0] === $user) {
                    $conflicts[] = 'user:' . $user;
                    break;
                }
            }
        }

        $databaseAvailable = false;
        if ($pdo instanceof PDO) {
            try { $databaseAvailable = $pdo->query('SELECT 1')->fetchColumn() == 1; }
            catch (Throwable) { $databaseAvailable = false; }
        }

        return [
            'installed_php_versions' => $installed,
            'php_fpm_versions' => $fpm,
            'nginx_config_valid' => null,
            'free_disk_bytes' => $free,
            'required_disk_bytes' => $request['required_disk_bytes'],
            'resource_conflicts' => array_values(array_unique($conflicts)),
            'dns_matches' => null,
            'database_available' => $databaseAvailable,
        ];
    }
}
