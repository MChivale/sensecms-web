CREATE TABLE IF NOT EXISTS chat_callback_receipts (
    conversation_id CHAR(36) PRIMARY KEY,
    status VARCHAR(12) NOT NULL,
    next_attempt_at TIMESTAMP NOT NULL,
    work_date DATE NOT NULL,
    locale VARCHAR(20) NOT NULL,
    timezone VARCHAR(64) NOT NULL,
    routed_at TIMESTAMP NULL DEFAULT NULL,
    route_after TIMESTAMP NULL DEFAULT NULL,
    calendar_event_id BIGINT NULL,
    FOREIGN KEY (conversation_id) REFERENCES ai_conversations(id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS chat_callback_receipts_due ON chat_callback_receipts (status, next_attempt_at);
CREATE TABLE IF NOT EXISTS chat_callback_recipients (
    conversation_id CHAR(36) NOT NULL,
    user_id BIGINT NOT NULL,
    status VARCHAR(12) NOT NULL,
    next_attempt_at TIMESTAMP NOT NULL,
    PRIMARY KEY (conversation_id,user_id),
    FOREIGN KEY (conversation_id) REFERENCES chat_callback_receipts(conversation_id) ON DELETE CASCADE
);
CREATE INDEX IF NOT EXISTS chat_callback_recipients_due ON chat_callback_recipients (status,next_attempt_at);
