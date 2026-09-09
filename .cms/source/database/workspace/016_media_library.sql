ALTER TABLE media ADD COLUMN IF NOT EXISTS uuid CHAR(36) NULL AFTER id;
ALTER TABLE media ADD COLUMN IF NOT EXISTS folder_id BIGINT UNSIGNED NULL AFTER uuid;
ALTER TABLE media ADD COLUMN IF NOT EXISTS facility_id BIGINT UNSIGNED NULL AFTER folder_id;
ALTER TABLE media ADD COLUMN IF NOT EXISTS uploaded_by BIGINT UNSIGNED NULL AFTER facility_id;
ALTER TABLE media ADD COLUMN IF NOT EXISTS checksum CHAR(64) NULL AFTER original_name;
ALTER TABLE media ADD COLUMN IF NOT EXISTS status ENUM('active','trash') NOT NULL DEFAULT 'active' AFTER checksum;
ALTER TABLE media ADD COLUMN IF NOT EXISTS deleted_at DATETIME NULL AFTER status;
ALTER TABLE media ADD COLUMN IF NOT EXISTS deleted_by BIGINT UNSIGNED NULL AFTER deleted_at;
UPDATE media SET uuid=UUID() WHERE uuid IS NULL OR uuid='';
ALTER TABLE media MODIFY uuid CHAR(36) NOT NULL;
ALTER TABLE media ADD UNIQUE INDEX IF NOT EXISTS media_uuid (uuid);
ALTER TABLE media ADD INDEX IF NOT EXISTS media_library_filter (status,facility_id,folder_id,created_at);
ALTER TABLE media ADD INDEX IF NOT EXISTS media_checksum (checksum);

CREATE TABLE IF NOT EXISTS media_folders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id BIGINT UNSIGNED NULL,
    facility_id BIGINT UNSIGNED NULL,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(140) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    archived_at DATETIME NULL,
    INDEX media_folder_parent (parent_id,sort_order,id),
    INDEX media_folder_scope (facility_id,archived_at)
);

CREATE TABLE IF NOT EXISTS media_translations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    media_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(20) NOT NULL,
    title VARCHAR(255) NULL,
    alt_text VARCHAR(255) NULL,
    caption TEXT NULL,
    description TEXT NULL,
    UNIQUE KEY media_translation_locale (media_id,locale),
    INDEX media_translation_search (locale,title)
);

CREATE TABLE IF NOT EXISTS media_tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    color CHAR(7) NOT NULL DEFAULT '#2563eb',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS media_tag_map (
    media_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (media_id,tag_id),
    INDEX media_tag_lookup (tag_id,media_id)
);

CREATE TABLE IF NOT EXISTS media_variants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    media_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(50) NOT NULL,
    path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    width INT NOT NULL,
    height INT NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY media_variant_name (media_id,name),
    INDEX media_variant_media (media_id)
);

INSERT INTO media_translations (media_id,locale,title,alt_text,caption,description)
SELECT m.id,(SELECT locale FROM languages WHERE enabled=1 ORDER BY is_default DESC,sort_order,id LIMIT 1),m.original_name,m.alt_text,m.caption,NULL
FROM media m
WHERE NOT EXISTS (SELECT 1 FROM media_translations mt WHERE mt.media_id=m.id);

INSERT INTO permissions (slug,name,group_key,description) VALUES
('content.media.global','Manage shared media','Content','Create and edit media available to every facility.'),
('content.media.delete','Delete media','Content','Move unused media to trash and permanently remove trashed files.')
ON DUPLICATE KEY UPDATE name=VALUES(name),group_key=VALUES(group_key),description=VALUES(description);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r INNER JOIN permissions p ON p.slug IN ('content.media.global','content.media.delete') WHERE r.slug IN ('owner','administrator','content-manager');
