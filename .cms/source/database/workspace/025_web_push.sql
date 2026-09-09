CREATE TABLE IF NOT EXISTS web_push_preferences (
    user_id BIGINT UNSIGNED NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    sources JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (user_id)
);

CREATE TABLE IF NOT EXISTS web_push_subscriptions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    endpoint_hash CHAR(64) NOT NULL,
    encrypted_subscription LONGTEXT NOT NULL,
    device_label VARCHAR(120) NOT NULL,
    content_encoding VARCHAR(20) NOT NULL DEFAULT 'aes128gcm',
    active TINYINT(1) NOT NULL DEFAULT 1,
    expires_at DATETIME NULL,
    last_seen_at DATETIME NOT NULL,
    last_success_at DATETIME NULL,
    last_failure_at DATETIME NULL,
    failure_count SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(180) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY web_push_endpoint (endpoint_hash),
    INDEX web_push_active_user (active,user_id),
    INDEX web_push_expiry (active,expires_at)
);

CREATE TABLE IF NOT EXISTS web_push_deliveries (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    subscription_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    event_key CHAR(64) NOT NULL,
    payload JSON NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    next_attempt_at DATETIME NOT NULL,
    http_status SMALLINT UNSIGNED NULL,
    last_error VARCHAR(180) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    UNIQUE KEY web_push_delivery_unique (subscription_id,event_key),
    INDEX web_push_delivery_queue (status,next_attempt_at,id),
    INDEX web_push_delivery_user (user_id,created_at)
);
