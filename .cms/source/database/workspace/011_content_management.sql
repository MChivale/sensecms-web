ALTER TABLE categories ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER updated_at;
ALTER TABLE categories ADD INDEX IF NOT EXISTS categories_archived_at (archived_at);
ALTER TABLE posts MODIFY status ENUM('draft','published','scheduled','archived') NOT NULL DEFAULT 'draft';
ALTER TABLE menu_items ADD COLUMN IF NOT EXISTS archived_at DATETIME NULL AFTER sort_order;
ALTER TABLE menu_items ADD INDEX IF NOT EXISTS menu_items_archived_at (archived_at);
CREATE TABLE IF NOT EXISTS category_translations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(20) NOT NULL,
    name VARCHAR(150) NOT NULL,
    description TEXT NULL,
    UNIQUE KEY category_locale (category_id, locale),
    INDEX category_translation_locale (locale)
);
INSERT IGNORE INTO category_translations (category_id, locale, name, description)
SELECT c.id, l.locale, REPLACE(c.slug, '-', ' '), '' FROM categories c INNER JOIN languages l ON l.enabled = 1;
INSERT IGNORE INTO menu_item_translations (menu_item_id, locale, label, url)
SELECT mi.id, l.locale, COALESCE(mi.label, ''), COALESCE(mi.url, '') FROM menu_items mi INNER JOIN languages l ON l.enabled = 1;
