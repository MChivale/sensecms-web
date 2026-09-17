ALTER TABLE ai_conversations
    ADD COLUMN IF NOT EXISTS visitor_ip VARCHAR(45) NULL,
    ADD COLUMN IF NOT EXISTS visitor_country CHAR(2) NULL;
