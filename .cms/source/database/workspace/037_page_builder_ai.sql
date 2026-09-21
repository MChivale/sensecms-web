ALTER TABLE ai_providers ADD COLUMN IF NOT EXISTS priority SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER enabled;
ALTER TABLE ai_providers ADD COLUMN IF NOT EXISTS verified_at DATETIME NULL AFTER priority;
ALTER TABLE ai_providers ADD COLUMN IF NOT EXISTS last_error VARCHAR(500) NULL AFTER verified_at;

CREATE TABLE IF NOT EXISTS page_builder_ai_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    facility_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    provider_slug VARCHAR(80) NOT NULL,
    model VARCHAR(150) NOT NULL,
    action VARCHAR(80) NOT NULL,
    scope ENUM('page','section') NOT NULL,
    locale VARCHAR(20) NOT NULL,
    status ENUM('requested','completed','failed') NOT NULL DEFAULT 'requested',
    input_chars INT UNSIGNED NOT NULL DEFAULT 0,
    output_chars INT UNSIGNED NOT NULL DEFAULT 0,
    input_tokens INT UNSIGNED NULL,
    output_tokens INT UNSIGNED NULL,
    error_code VARCHAR(80) NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    INDEX builder_ai_rate_limit (user_id, created_at),
    INDEX builder_ai_page_history (page_id, created_at),
    INDEX builder_ai_provider_usage (provider_slug, created_at)
);

INSERT INTO permissions (slug,name,group_key,description) VALUES
('content.pages.ai','Use AI in Page Builder','Content','Create reviewable AI proposals for pages and sections without saving or publishing automatically.')
ON DUPLICATE KEY UPDATE name=VALUES(name),group_key=VALUES(group_key),description=VALUES(description);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='owner' AND p.slug='content.pages.ai';
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator' AND p.slug='content.pages.ai';
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug IN ('content-manager','editor') AND p.slug='content.pages.ai';
