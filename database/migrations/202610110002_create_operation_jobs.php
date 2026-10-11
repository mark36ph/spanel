<?php
declare(strict_types=1);

/**
 * Add the durable queue used by future provisioning and maintenance workers.
 * Payloads/results must contain validated non-secret metadata, never commands
 * or credentials. This is additive so an interrupted application can be
 * inspected and repaired without deleting existing website data.
 */
return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS operation_jobs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        server_id BIGINT UNSIGNED NULL,
        website_id BIGINT UNSIGNED NULL,
        operation_type VARCHAR(80) NOT NULL,
        status ENUM('queued','running','succeeded','failed','rollback_required','cancelled') NOT NULL DEFAULT 'queued',
        payload_json JSON NULL,
        result_json JSON NULL,
        error_code VARCHAR(100) NULL,
        error_summary VARCHAR(1000) NULL,
        requested_by_admin_id BIGINT UNSIGNED NULL,
        attempt_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
        idempotency_key VARCHAR(190) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        started_at DATETIME NULL,
        finished_at DATETIME NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_operation_jobs_idempotency (idempotency_key),
        KEY idx_operation_jobs_queue (status, created_at, id),
        KEY idx_operation_jobs_server_status (server_id, status, created_at),
        KEY idx_operation_jobs_website (website_id, created_at),
        KEY idx_operation_jobs_requested_by (requested_by_admin_id, created_at),
        CONSTRAINT fk_operation_jobs_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE SET NULL,
        CONSTRAINT fk_operation_jobs_website FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE SET NULL,
        CONSTRAINT fk_operation_jobs_admin FOREIGN KEY (requested_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
