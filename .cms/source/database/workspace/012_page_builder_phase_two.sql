ALTER TABLE content_blocks ADD COLUMN IF NOT EXISTS global_section_id BIGINT UNSIGNED NULL AFTER source_theme;
ALTER TABLE content_blocks ADD COLUMN IF NOT EXISTS visible_from DATETIME NULL AFTER visible;
ALTER TABLE content_blocks ADD COLUMN IF NOT EXISTS visible_until DATETIME NULL AFTER visible_from;
ALTER TABLE content_blocks ADD INDEX IF NOT EXISTS block_global_section (global_section_id);
ALTER TABLE content_blocks ADD INDEX IF NOT EXISTS block_visibility_window (page_id, archived_at, visible, visible_from, visible_until);

CREATE TABLE IF NOT EXISTS global_sections (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uid CHAR(36) NOT NULL,
    name VARCHAR(120) NOT NULL,
    type VARCHAR(80) NOT NULL,
    source_theme VARCHAR(100) NOT NULL,
    settings JSON NULL,
    version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    archived_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY global_section_uid (uid),
    INDEX global_section_active (active, archived_at, updated_at),
    INDEX global_section_type (type)
);

CREATE TABLE IF NOT EXISTS global_section_translations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    global_section_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(20) NOT NULL,
    data JSON NOT NULL,
    UNIQUE KEY global_section_locale (global_section_id, locale),
    INDEX global_section_translation_locale (locale)
);

CREATE TABLE IF NOT EXISTS page_builder_revisions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    page_id BIGINT UNSIGNED NOT NULL,
    version BIGINT UNSIGNED NOT NULL,
    snapshot JSON NOT NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY page_builder_revision_version (page_id, version),
    INDEX page_builder_revision_created (page_id, created_at)
);
