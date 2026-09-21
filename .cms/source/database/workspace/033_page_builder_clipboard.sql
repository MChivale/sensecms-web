CREATE TABLE IF NOT EXISTS page_builder_clipboards (
    user_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    source_page_id BIGINT UNSIGNED NULL,
    blocks_json JSON NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX page_builder_clipboard_updated (updated_at)
);
