CREATE TABLE IF NOT EXISTS social_connections (
    plugin_slug VARCHAR(100) NOT NULL PRIMARY KEY,
    external_account_id VARCHAR(191) NOT NULL,
    display_name VARCHAR(180) NOT NULL,
    encrypted_credentials LONGTEXT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    last_verified_at DATETIME NULL,
    last_error VARCHAR(300) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS social_post_targets (
    post_id BIGINT UNSIGNED NOT NULL,
    plugin_slug VARCHAR(100) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    message TEXT NULL,
    revision INT UNSIGNED NOT NULL DEFAULT 1,
    last_enqueued_revision INT UNSIGNED NOT NULL DEFAULT 0,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (post_id, plugin_slug),
    INDEX social_targets_plugin (plugin_slug, enabled),
    INDEX social_targets_due (enabled, last_enqueued_revision, revision)
);

CREATE TABLE IF NOT EXISTS social_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    post_id BIGINT UNSIGNED NOT NULL,
    plugin_slug VARCHAR(100) NOT NULL,
    target_revision INT UNSIGNED NOT NULL,
    payload LONGTEXT NOT NULL,
    payload_hash CHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    locked_at DATETIME NULL,
    external_id VARCHAR(255) NULL,
    external_url VARCHAR(1000) NULL,
    last_error VARCHAR(300) NULL,
    created_at DATETIME NOT NULL,
    published_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY social_delivery_revision (post_id, plugin_slug, target_revision),
    INDEX social_delivery_queue (status, available_at),
    INDEX social_delivery_post (post_id, created_at)
);

INSERT IGNORE INTO permissions (slug, name, group_key, description) VALUES
('social.view', 'View social publishing', 'Social publishing', 'View social connections and delivery status.'),
('social.publish', 'Publish to social networks', 'Social publishing', 'Select destinations, queue posts and retry deliveries.'),
('social.settings.manage', 'Manage social connections', 'Social publishing', 'Connect and disconnect social network accounts.');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'owner' AND p.slug LIKE 'social.%';
