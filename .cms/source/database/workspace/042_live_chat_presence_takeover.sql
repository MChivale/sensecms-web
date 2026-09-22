CREATE TABLE IF NOT EXISTS live_chat_operator_presence (
    user_id BIGINT UNSIGNED PRIMARY KEY,
    last_seen_at DATETIME NOT NULL,
    INDEX(last_seen_at)
);

ALTER TABLE ai_conversations
    ADD COLUMN IF NOT EXISTS queued_at DATETIME NULL AFTER status,
    ADD COLUMN IF NOT EXISTS ai_takeover_at DATETIME NULL AFTER queued_at,
    ADD INDEX IF NOT EXISTS ai_takeover_queue (status, ai_takeover_at);
