CREATE TABLE IF NOT EXISTS page_builder_drafts (
    page_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    base_version BIGINT UNSIGNED NOT NULL,
    snapshot JSON NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (page_id, user_id),
    INDEX page_builder_draft_user (user_id, updated_at),
    INDEX page_builder_draft_page (page_id, updated_at)
);
