ALTER TABLE content_blocks ADD COLUMN IF NOT EXISTS uid CHAR(36) NULL AFTER id;
ALTER TABLE content_blocks ADD COLUMN IF NOT EXISTS source_theme VARCHAR(100) NULL AFTER type;
ALTER TABLE content_blocks ADD COLUMN IF NOT EXISTS settings JSON NULL AFTER source_theme;
ALTER TABLE content_blocks ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER visible;
UPDATE content_blocks SET uid = UUID() WHERE uid IS NULL OR uid = '';
ALTER TABLE content_blocks MODIFY uid CHAR(36) NOT NULL;
ALTER TABLE content_blocks ADD UNIQUE INDEX IF NOT EXISTS block_uid (uid);
ALTER TABLE content_blocks ADD INDEX IF NOT EXISTS page_archive_order (page_id, archived_at, sort_order);
CREATE TABLE IF NOT EXISTS page_builder_states (
    page_id BIGINT UNSIGNED PRIMARY KEY,
    version BIGINT UNSIGNED NOT NULL DEFAULT 0,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL
);
