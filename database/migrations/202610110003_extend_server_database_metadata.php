<?php
declare(strict_types=1);

/**
 * Extend hosting node metadata and bind database metadata to its hosting node.
 * Existing rows are backfilled from their owning website; legacy domain data
 * remains untouched for compatibility.
 */
return static function (PDO $pdo): void {
    $serverColumns = $pdo->query("SHOW COLUMNS FROM servers")->fetchAll(PDO::FETCH_COLUMN, 0);
    $addServerColumn = static function (string $column, string $definition) use ($pdo, &$serverColumns): void {
        if (!in_array($column, $serverColumns, true)) {
            $pdo->exec("ALTER TABLE servers ADD COLUMN {$column} {$definition}");
            $serverColumns[] = $column;
        }
    };
    $addServerColumn('ipv4_address', 'VARCHAR(45) NULL');
    $addServerColumn('ipv6_address', 'VARCHAR(45) NULL');
    $addServerColumn('panel_version', 'VARCHAR(40) NULL');
    $addServerColumn('agent_version', 'VARCHAR(40) NULL');
    $addServerColumn('capabilities_json', 'JSON NULL');
    $addServerColumn('last_heartbeat_at', 'DATETIME NULL');

    $databaseColumns = $pdo->query("SHOW COLUMNS FROM website_databases")->fetchAll(PDO::FETCH_COLUMN, 0);
    if (!in_array('server_id', $databaseColumns, true)) {
        $pdo->exec('ALTER TABLE website_databases ADD COLUMN server_id BIGINT UNSIGNED NULL AFTER website_id');
    }
    $pdo->exec('UPDATE website_databases d INNER JOIN websites w ON w.id = d.website_id SET d.server_id = w.server_id WHERE d.server_id IS NULL');

    $indexExists = $pdo->query("SHOW INDEX FROM website_databases WHERE Key_name = 'idx_website_databases_server'")->fetch();
    if (!$indexExists) {
        $pdo->exec('ALTER TABLE website_databases ADD KEY idx_website_databases_server (server_id)');
    }
    $fkExists = $pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'website_databases' AND CONSTRAINT_NAME = 'fk_website_databases_server'")->fetchColumn();
    if (!$fkExists) {
        $pdo->exec('ALTER TABLE website_databases ADD CONSTRAINT fk_website_databases_server FOREIGN KEY (server_id) REFERENCES servers(id) ON DELETE RESTRICT');
    }
};
