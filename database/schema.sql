CREATE TABLE IF NOT EXISTS admins (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at DATETIME NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Hosting nodes. The installer will register the first local server.
-- Secrets/credentials must be stored outside this table in root-owned configuration.
CREATE TABLE IF NOT EXISTS servers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(120) NOT NULL,
    hostname VARCHAR(253) NOT NULL,
    environment ENUM('development','production') NOT NULL DEFAULT 'development',
    status ENUM('pending','online','offline','maintenance','error') NOT NULL DEFAULT 'pending',
    is_local TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_servers_hostname (hostname)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS websites (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    server_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    domain VARCHAR(253) NOT NULL,
    document_root VARCHAR(500) NOT NULL,
    php_version VARCHAR(20) NULL,
    status ENUM('pending','active','disabled','error') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_websites_domain (domain),
    KEY idx_websites_server_status (server_id, status),
    CONSTRAINT fk_websites_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS website_databases (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    website_id BIGINT UNSIGNED NOT NULL,
    database_name VARCHAR(64) NOT NULL,
    database_user VARCHAR(64) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_website_databases_name (database_name),
    UNIQUE KEY uq_website_databases_user (database_user),
    CONSTRAINT fk_website_databases_website FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    admin_id BIGINT UNSIGNED NULL,
    event_type VARCHAR(100) NOT NULL,
    target_type VARCHAR(100) NULL,
    target_id VARCHAR(100) NULL,
    details_json JSON NULL,
    remote_addr VARCHAR(45) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_events_created_at (created_at),
    CONSTRAINT fk_audit_events_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Applied schema changes are tracked here by the installer/updater migration runner.
CREATE TABLE IF NOT EXISTS schema_migrations (
    migration_id VARCHAR(190) NOT NULL,
    checksum CHAR(64) NOT NULL,
    applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (migration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
