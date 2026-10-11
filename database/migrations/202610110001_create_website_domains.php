<?php
declare(strict_types=1);

/**
 * Add the normalized domain relation while retaining websites.domain for
 * compatibility. This migration deliberately refuses ambiguous legacy data.
 */
return static function (PDO $pdo): void {
    $websites = $pdo->query(
        'SELECT id, domain, server_id FROM websites ORDER BY id'
    )->fetchAll(PDO::FETCH_ASSOC);

    $normalised = [];
    foreach ($websites as $website) {
        $raw = trim((string)$website['domain']);
        $domain = strtolower(rtrim($raw, '.'));
        if ($domain === '' || strlen($domain) > 253
            || preg_match('/\s/', $domain)
            || !preg_match('/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*$/D', $domain)) {
            throw new RuntimeException("Website ID {$website['id']} has an invalid domain. Correct it before retrying.");
        }
        if (isset($normalised[$domain])) {
            throw new RuntimeException("Domain collision after normalization: website IDs {$normalised[$domain]} and {$website['id']} both map to {$domain}. Resolve manually before retrying.");
        }
        $normalised[$domain] = (int)$website['id'];
        if ($website['server_id'] === null) {
            throw new RuntimeException("Website ID {$website['id']} has no server assignment. Assign it to the intended server before retrying.");
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS website_domains (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        website_id BIGINT UNSIGNED NOT NULL,
        domain VARCHAR(253) NOT NULL,
        domain_type ENUM('primary','alias','subdomain') NOT NULL DEFAULT 'primary',
        is_primary TINYINT(1) NOT NULL DEFAULT 0,
        status ENUM('pending','active','disabled') NOT NULL DEFAULT 'pending',
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_website_domains_domain (domain),
        KEY idx_website_domains_website (website_id),
        KEY idx_website_domains_primary (website_id, is_primary),
        CONSTRAINT fk_website_domains_website FOREIGN KEY (website_id) REFERENCES websites(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $insert = $pdo->prepare(
        "INSERT INTO website_domains (website_id, domain, domain_type, is_primary, status)
         VALUES (:website_id, :domain, 'primary', 1, 'pending')
         ON DUPLICATE KEY UPDATE domain = VALUES(domain)"
    );
    foreach ($websites as $website) {
        $domain = strtolower(rtrim(trim((string)$website['domain']), '.'));
        $check = $pdo->prepare('SELECT website_id FROM website_domains WHERE domain = :domain LIMIT 1');
        $check->execute(['domain' => $domain]);
        $owner = $check->fetchColumn();
        if ($owner !== false && (int)$owner !== (int)$website['id']) {
            throw new RuntimeException("Domain {$domain} is already assigned to another website. No domain mapping was overwritten.");
        }
        $insert->execute(['website_id' => (int)$website['id'], 'domain' => $domain]);
    }

    $missing = (int)$pdo->query(
        'SELECT COUNT(*) FROM websites w LEFT JOIN website_domains d ON d.website_id = w.id AND d.is_primary = 1 WHERE d.id IS NULL'
    )->fetchColumn();
    if ($missing !== 0) {
        throw new RuntimeException("Post-check failed: {$missing} website(s) have no primary domain mapping.");
    }
};
