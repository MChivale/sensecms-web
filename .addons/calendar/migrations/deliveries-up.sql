CREATE TABLE IF NOT EXISTS calendar_deliveries (
    reminder_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(20) NOT NULL,
    last_error VARCHAR(200) NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (reminder_id,user_id),
    INDEX calendar_delivery_status (status,updated_at)
);
