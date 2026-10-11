<?php
declare(strict_types=1);

require_once __DIR__ . '/ProvisioningPlan.php';

/**
 * Produces a fixed, data-only sequence for a future restricted operations
 * service. This class never executes steps or modifies the server.
 */
final class ProvisioningWorkflow
{
    public static function build(array $preflightResponse, bool $createDatabase): array
    {
        if (($preflightResponse['ok'] ?? null) !== true
            || !is_int($preflightResponse['website_id'] ?? null)
            || $preflightResponse['website_id'] < 1
            || !is_string($preflightResponse['domain'] ?? null)
            || !is_array($preflightResponse['evaluation'] ?? null)
            || ($preflightResponse['evaluation']['ready'] ?? null) !== true
            || !is_string($preflightResponse['evaluation']['selected_php_version'] ?? null)) {
            throw new InvalidArgumentException('A successful, ready preflight is required before planning provisioning.');
        }

        $plan = ProvisioningPlan::build(
            $preflightResponse['website_id'],
            $preflightResponse['domain'],
            $preflightResponse['evaluation']['selected_php_version']
        );

        $steps = [
            self::step('create_site_identity', 'Create dedicated Linux identity', 'create_user', 'remove_user_if_created_by_job'),
            self::step('create_site_directories', 'Create isolated website directories and permissions', 'create_directories', 'remove_directories_if_created_by_job'),
            self::step('stage_php_fpm_pool', 'Stage the fixed PHP-FPM pool configuration', 'stage_fpm_pool_config', 'remove_staged_fpm_config'),
            self::step('validate_php_fpm', 'Validate PHP-FPM configuration', 'test_fpm_config', 'none'),
            self::step('activate_php_fpm_pool', 'Activate pool and reload the selected PHP-FPM service', 'activate_fpm_pool', 'restore_previous_fpm_config_and_reload'),
        ];

        if ($createDatabase) {
            $steps[] = self::step(
                'create_site_database',
                'Create isolated database and least-privilege database user',
                'create_database',
                'drop_database_user_and_database_only_if_created_by_job'
            );
        }

        $steps[] = self::step('stage_nginx_site', 'Stage fixed-template Nginx site configuration', 'stage_nginx_config', 'restore_previous_nginx_config');
        $steps[] = self::step('validate_nginx', 'Validate staged Nginx configuration', 'test_nginx_config', 'none');
        $steps[] = self::step('enable_nginx_site', 'Enable site and validate complete Nginx configuration', 'enable_nginx_site', 'disable_site_and_restore_previous_config');
        $steps[] = self::step('reload_nginx', 'Reload Nginx after successful validation', 'reload_nginx', 'restore_previous_nginx_config_and_reload');
        $steps[] = self::step('verify_site', 'Verify local HTTP response and expected document root', 'health_check_site', 'mark_recovery_required');

        return [
            'website_id' => $plan['website_id'],
            'domain' => $plan['domain'],
            'php_version' => $plan['php_version'],
            'resources' => [
                'linux_user' => $plan['linux_user'],
                'document_root' => $plan['document_root'],
                'php_fpm_pool_path' => $plan['php_fpm_pool_path'],
                'nginx_site_path' => $plan['nginx_site_path'],
                'database_name' => $createDatabase ? $plan['database_name'] : null,
                'database_user' => $createDatabase ? $plan['database_user'] : null,
            ],
            'steps' => $steps,
            'execution_enabled' => false,
            'execution_note' => 'Planning only. The restricted operations service must independently validate and execute each allowlisted step.',
        ];
    }

    private static function step(string $id, string $label, string $operation, string $rollback): array
    {
        return [
            'id' => $id,
            'label' => $label,
            'operation' => $operation,
            'rollback' => $rollback,
        ];
    }
}
