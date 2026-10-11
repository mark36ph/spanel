<?php
declare(strict_types=1);

/**
 * Prepare database metadata for protected credential references and the
 * short-lived, single-use phpMyAdmin handoff. This migration stores no
 * database passwords and does not enable direct login by itself.
 */
return static function (PDO $pdo): void {
    $columns = $pdo->query('SHOW COLUMNS FROM website_databases')->fetchAll(PDO::FETCH_COLUMN, 0);

    if (!in_array('status', $columns, true)) {
        $pdo->exec("ALTER TABLE website_databases ADD COLUMN status ENUM('pending','active','error','disabled') NOT NULL DEFAULT 'active' AFTER database_user");
    }
    if (!in_array('secret_ref', $columns, true)) {
        $pdo->exec('ALTER TABLE website_databases ADD COLUMN secret_ref VARCHAR(190) NULL AFTER status');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS phpmyadmin_handoffs (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        token_hash CHAR(64) NOT NULL,
        admin_id BIGINT UNSIGNED NOT NULL,
        website_database_id BIGINT UNSIGNED NOT NULL,
        expires_at DATETIME NOT NULL,
        consumed_at DATETIME NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        remote_addr VARCHAR(45) NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_phpmyadmin_handoffs_token_hash (token_hash),
        KEY idx_phpmyadmin_handoffs_expiry (expires_at, consumed_at),
        KEY idx_phpmyadmin_handoffs_admin (admin_id, created_at),
        CONSTRAINT fk_phpmyadmin_handoffs_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE,
        CONSTRAINT fk_phpmyadmin_handoffs_database FOREIGN KEY (website_database_id) REFERENCES website_databases(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
};
