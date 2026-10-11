<?php
declare(strict_types=1);

/** Add privacy-preserving login throttling and latest local service health snapshots. */
return static function (PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS login_rate_limits (
        scope_type ENUM('email','ip') NOT NULL,
        identifier_hash CHAR(64) NOT NULL,
        attempts INT UNSIGNED NOT NULL DEFAULT 0,
        window_started_at DATETIME NOT NULL,
        locked_until DATETIME NULL,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (scope_type, identifier_hash),
        KEY idx_login_rate_limits_locked_until (locked_until),
        KEY idx_login_rate_limits_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS server_health_checks (
        server_id BIGINT UNSIGNED NOT NULL,
        service_key VARCHAR(64) NOT NULL,
        status ENUM('ok','warning','critical','unknown') NOT NULL DEFAULT 'unknown',
        details VARCHAR(500) NOT NULL,
        checked_at DATETIME NOT NULL,
        PRIMARY KEY (server_id, service_key),
        KEY idx_server_health_checks_status_checked (status, checked_at),
        CONSTRAINT fk_server_health_checks_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
