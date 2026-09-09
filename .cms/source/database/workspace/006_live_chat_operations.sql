CREATE TABLE IF NOT EXISTS live_chat_teams (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    description VARCHAR(500) NULL,
    color CHAR(7) NOT NULL DEFAULT '#2563eb',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX(active)
);

CREATE TABLE IF NOT EXISTS live_chat_team_users (
    team_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY(team_id, user_id),
    INDEX(user_id)
);

CREATE TABLE IF NOT EXISTS live_chat_reads (
    conversation_id CHAR(36) NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    read_at DATETIME NOT NULL,
    PRIMARY KEY(conversation_id, user_id),
    INDEX(user_id, read_at)
);

CREATE TABLE IF NOT EXISTS live_chat_transfers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    conversation_id CHAR(36) NOT NULL,
    from_user_id BIGINT UNSIGNED NOT NULL,
    to_user_id BIGINT UNSIGNED NULL,
    to_team_id BIGINT UNSIGNED NULL,
    note VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    INDEX(conversation_id, created_at),
    INDEX(to_user_id, created_at),
    INDEX(to_team_id, created_at)
);

ALTER TABLE ai_conversations ADD COLUMN IF NOT EXISTS assigned_team_id BIGINT UNSIGNED NULL AFTER assigned_user_id;
ALTER TABLE ai_conversations ADD INDEX IF NOT EXISTS assigned_team_status (assigned_team_id, status);
ALTER TABLE ai_conversations ADD INDEX IF NOT EXISTS assigned_user_status (assigned_user_id, status);
ALTER TABLE ai_messages ADD COLUMN IF NOT EXISTS user_id BIGINT UNSIGNED NULL AFTER provider_slug;
ALTER TABLE ai_messages ADD INDEX IF NOT EXISTS conversation_role_id (conversation_id, role, id);
