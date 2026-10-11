<?php
declare(strict_types=1);

require_once __DIR__ . '/DomainName.php';

/**
 * Produces deterministic, data-only resource names and config templates.
 * This class never writes files, changes services, or executes commands.
 */
final class ProvisioningPlan
{
    public static function build(int $websiteId, string $domain, string $phpVersion): array
    {
        if ($websiteId < 1) {
            throw new InvalidArgumentException('Website ID is outside the supported range.');
        }
        if (DomainName::normalize($domain) !== $domain) {
            throw new InvalidArgumentException('Domain must already be normalized.');
        }
        if (!in_array($phpVersion, ['8.2', '8.3'], true)) {
            throw new InvalidArgumentException('PHP version is not supported.');
        }

        $id = (string) $websiteId;
        $linuxUser = 'spw' . $id;
        $poolName = 'spanel-site-' . $id;
        $documentRoot = '/var/www/spanel/sites/' . $id . '/public';
        $socket = '/run/php/' . $poolName . '.sock';
        $databaseName = 'spw_' . $id;
        $databaseUser = 'spu_' . $id;

        $nginx = <<<NGINX
server {
    listen 80;
    listen [::]:80;
    server_name {$domain};

    root {$documentRoot};
    index index.php index.html;
    server_tokens off;

    access_log /var/log/nginx/spanel-site-{$id}-access.log;
    error_log /var/log/nginx/spanel-site-{$id}-error.log warn;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php$ {
        try_files \$uri =404;
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:{$socket};
    }

    location ~ /.(?!well-known).* {
        deny all;
    }
}
NGINX;

        $pool = <<<POOL
[{$poolName}]
user = {$linuxUser}
group = {$linuxUser}
listen = {$socket}
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = 5
pm.process_idle_timeout = 10s
pm.max_requests = 500
clear_env = yes
security.limit_extensions = .php
POOL;

        return [
            'website_id' => $websiteId,
            'domain' => $domain,
            'php_version' => $phpVersion,
            'linux_user' => $linuxUser,
            'pool_name' => $poolName,
            'document_root' => $documentRoot,
            'php_fpm_socket' => $socket,
            'nginx_site_path' => '/etc/nginx/sites-available/spanel-site-' . $id,
            'php_fpm_pool_path' => '/etc/php/' . $phpVersion . '/fpm/pool.d/' . $poolName . '.conf',
            'database_name' => $databaseName,
            'database_user' => $databaseUser,
            'nginx_config' => $nginx,
            'php_fpm_pool_config' => $pool,
        ];
    }
}
