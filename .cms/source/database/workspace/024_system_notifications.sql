CREATE TABLE IF NOT EXISTS notification_channel_settings (
    plugin_slug VARCHAR(100) NOT NULL,
    subject_type VARCHAR(20) NOT NULL DEFAULT 'installation',
    subject_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    encrypted_settings LONGTEXT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    last_verified_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (plugin_slug,subject_type,subject_id)
);

CREATE TABLE IF NOT EXISTS notification_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_key CHAR(64) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    source VARCHAR(30) NOT NULL,
    subject VARCHAR(100) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    url VARCHAR(500) NOT NULL,
    channels VARCHAR(100) NOT NULL DEFAULT '',
    context JSON NULL,
    created_at DATETIME NOT NULL,
    processed_at DATETIME NULL,
    UNIQUE KEY notification_event_unique (event_key,user_id),
    INDEX notification_event_pending (processed_at,id)
);

CREATE TABLE IF NOT EXISTS notification_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    plugin_slug VARCHAR(100) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    event_key CHAR(64) NOT NULL,
    payload JSON NOT NULL,
    settings_revision CHAR(64) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    last_error VARCHAR(180) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY notification_delivery_unique (plugin_slug,user_id,event_key),
    INDEX notification_delivery_queue (status,id)
);

CREATE TABLE IF NOT EXISTS notification_cursors (
    source VARCHAR(40) PRIMARY KEY,
    cursor_value VARCHAR(120) NOT NULL,
    updated_at DATETIME NOT NULL
);

ALTER TABLE survey_responses ADD INDEX IF NOT EXISTS notification_completed (completed_at,id);
ALTER TABLE ai_messages ADD INDEX IF NOT EXISTS notification_created (created_at,id);
ALTER TABLE form_submissions ADD INDEX IF NOT EXISTS notification_created (created_at,id);
ALTER TABLE user_notifications ADD INDEX IF NOT EXISTS notification_created (created_at,id);
