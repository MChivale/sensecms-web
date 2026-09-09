CREATE TABLE IF NOT EXISTS email_action_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    purpose VARCHAR(32) NOT NULL,
    token_hash CHAR(64) NOT NULL,
    request_ip_hash CHAR(64) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY email_action_token_hash (token_hash),
    INDEX email_action_user (user_id,purpose,created_at),
    INDEX email_action_expiry (purpose,used_at,expires_at)
);
