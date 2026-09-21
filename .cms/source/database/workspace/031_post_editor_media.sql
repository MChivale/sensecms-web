ALTER TABLE posts ADD COLUMN IF NOT EXISTS audio_media_id BIGINT UNSIGNED NULL AFTER featured_media_id;
ALTER TABLE posts ADD COLUMN IF NOT EXISTS video_media_id BIGINT UNSIGNED NULL AFTER audio_media_id;
ALTER TABLE posts ADD INDEX IF NOT EXISTS post_audio_media (audio_media_id);
ALTER TABLE posts ADD INDEX IF NOT EXISTS post_video_media (video_media_id);

CREATE TABLE IF NOT EXISTS post_tags (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    locale VARCHAR(20) NOT NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY post_tag_locale_slug (locale, slug),
    INDEX post_tag_locale_name (locale, name)
);

CREATE TABLE IF NOT EXISTS post_tag_map (
    post_id BIGINT UNSIGNED NOT NULL,
    tag_id BIGINT UNSIGNED NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY (post_id, tag_id),
    INDEX post_tag_lookup (tag_id, post_id)
);
