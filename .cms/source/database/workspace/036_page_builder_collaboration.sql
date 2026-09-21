CREATE TABLE IF NOT EXISTS page_builder_section_workflow (
    page_id BIGINT UNSIGNED NOT NULL,
    section_uid CHAR(36) NOT NULL,
    status ENUM('draft','in_review','approved') NOT NULL DEFAULT 'draft',
    assigned_user_id BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (page_id, section_uid),
    INDEX builder_section_workflow_queue (status, assigned_user_id, updated_at)
);

CREATE TABLE IF NOT EXISTS page_builder_section_comments (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    section_uid CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    body VARCHAR(2000) NOT NULL,
    resolved_at DATETIME NULL,
    resolved_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX builder_section_comment_thread (page_id, section_uid, resolved_at, created_at),
    INDEX builder_section_comment_user (user_id, created_at)
);

CREATE TABLE IF NOT EXISTS page_builder_section_locks (
    page_id BIGINT UNSIGNED NOT NULL,
    section_uid CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    expires_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (page_id, section_uid),
    INDEX builder_section_lock_expiry (expires_at)
);

INSERT INTO permissions (slug,name,group_key,description) VALUES
('content.pages.collaborate','Collaborate in Page Builder','Editorial workflow','Comment on, assign and review individual Page Builder sections.')
ON DUPLICATE KEY UPDATE name=VALUES(name),group_key=VALUES(group_key),description=VALUES(description);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='owner' AND p.slug='content.pages.collaborate';
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator' AND p.slug='content.pages.collaborate';
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug IN ('content-manager','editor','reviewer') AND p.slug='content.pages.collaborate';
