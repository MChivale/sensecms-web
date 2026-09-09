CREATE TABLE IF NOT EXISTS facilities (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uid CHAR(36) NOT NULL UNIQUE,
    city_slug VARCHAR(120) NOT NULL,
    facility_slug VARCHAR(120) NOT NULL,
    status ENUM('draft','active','archived') NOT NULL DEFAULT 'draft',
    is_primary TINYINT(1) NOT NULL DEFAULT 0,
    homepage_page_id BIGINT UNSIGNED NULL,
    email VARCHAR(190) NULL,
    phone VARCHAR(50) NULL,
    secondary_phone VARCHAR(50) NULL,
    website_url VARCHAR(500) NULL,
    map_url VARCHAR(1000) NULL,
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    timezone VARCHAR(80) NOT NULL DEFAULT 'UTC',
    search_enabled TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY facility_route (city_slug, facility_slug),
    INDEX facility_status_order (status, sort_order),
    INDEX facility_primary (is_primary)
);

CREATE TABLE IF NOT EXISTS facility_translations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(20) NOT NULL,
    name VARCHAR(180) NOT NULL,
    city_name VARCHAR(150) NOT NULL,
    short_description TEXT NULL,
    address TEXT NULL,
    seo_title VARCHAR(255) NULL,
    seo_description TEXT NULL,
    UNIQUE KEY facility_locale (facility_id, locale),
    INDEX facility_translation_locale (locale)
);

ALTER TABLE pages ADD COLUMN IF NOT EXISTS facility_id BIGINT UNSIGNED NULL AFTER parent_id;
ALTER TABLE posts ADD COLUMN IF NOT EXISTS facility_id BIGINT UNSIGNED NULL AFTER author_id;
ALTER TABLE form_submissions ADD COLUMN IF NOT EXISTS facility_id BIGINT UNSIGNED NULL AFTER page_id;
ALTER TABLE page_translations ADD COLUMN IF NOT EXISTS facility_id BIGINT UNSIGNED NULL AFTER page_id;
ALTER TABLE post_translations ADD COLUMN IF NOT EXISTS facility_id BIGINT UNSIGNED NULL AFTER post_id;

UPDATE pages SET facility_id=(SELECT id FROM facilities WHERE is_primary=1 ORDER BY id LIMIT 1) WHERE facility_id IS NULL;
UPDATE posts SET facility_id=(SELECT id FROM facilities WHERE is_primary=1 ORDER BY id LIMIT 1) WHERE facility_id IS NULL;
UPDATE page_translations t INNER JOIN pages p ON p.id=t.page_id SET t.facility_id=p.facility_id WHERE t.facility_id IS NULL;
UPDATE post_translations t INNER JOIN posts p ON p.id=t.post_id SET t.facility_id=p.facility_id WHERE t.facility_id IS NULL;
UPDATE form_submissions s INNER JOIN pages p ON p.id=s.page_id SET s.facility_id=p.facility_id WHERE s.facility_id IS NULL;

ALTER TABLE pages MODIFY facility_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE posts MODIFY facility_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE page_translations MODIFY facility_id BIGINT UNSIGNED NOT NULL;
ALTER TABLE post_translations MODIFY facility_id BIGINT UNSIGNED NOT NULL;

ALTER TABLE pages ADD INDEX IF NOT EXISTS page_facility_status (facility_id, status);
ALTER TABLE posts ADD INDEX IF NOT EXISTS post_facility_status (facility_id, status);
ALTER TABLE form_submissions ADD INDEX IF NOT EXISTS submission_facility (facility_id);
ALTER TABLE page_translations DROP INDEX IF EXISTS locale_slug;
ALTER TABLE post_translations DROP INDEX IF EXISTS post_slug;
ALTER TABLE page_translations ADD UNIQUE INDEX IF NOT EXISTS facility_locale_slug (facility_id, locale, slug);
ALTER TABLE post_translations ADD UNIQUE INDEX IF NOT EXISTS facility_post_slug (facility_id, locale, slug);

UPDATE facilities c SET homepage_page_id=(SELECT p.id FROM pages p INNER JOIN page_translations t ON t.page_id=p.id WHERE p.facility_id=c.id AND t.slug='home' ORDER BY p.id LIMIT 1) WHERE c.is_primary=1 AND c.homepage_page_id IS NULL;
