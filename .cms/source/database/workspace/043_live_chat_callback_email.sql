ALTER TABLE ai_conversations
    ADD COLUMN IF NOT EXISTS email_requested_at DATETIME NULL AFTER ai_takeover_at;
