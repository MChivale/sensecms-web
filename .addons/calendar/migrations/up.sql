CREATE TABLE IF NOT EXISTS calendar_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uid CHAR(36) NOT NULL UNIQUE,
    series_uid CHAR(36) NULL,
    facility_id BIGINT UNSIGNED NULL,
    owner_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(180) NOT NULL,
    description TEXT NULL,
    event_type VARCHAR(40) NOT NULL DEFAULT 'general',
    status VARCHAR(30) NOT NULL DEFAULT 'confirmed',
    visibility VARCHAR(20) NOT NULL DEFAULT 'participants',
    location VARCHAR(255) NULL,
    color CHAR(7) NOT NULL DEFAULT '#2563eb',
    timezone VARCHAR(64) NOT NULL DEFAULT 'UTC',
    start_at DATETIME NOT NULL,
    end_at DATETIME NOT NULL,
    all_day TINYINT(1) NOT NULL DEFAULT 0,
    recurrence VARCHAR(20) NOT NULL DEFAULT 'none',
    recurrence_until DATE NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX calendar_events_range (start_at, end_at),
    INDEX calendar_events_facility (facility_id, start_at),
    INDEX calendar_events_series (series_uid),
    INDEX calendar_events_owner (owner_id, start_at)
);

CREATE TABLE IF NOT EXISTS calendar_event_participants (
    event_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    participant_role VARCHAR(30) NOT NULL DEFAULT 'required',
    response_status VARCHAR(30) NOT NULL DEFAULT 'pending',
    responded_at DATETIME NULL,
    PRIMARY KEY (event_id, user_id),
    INDEX calendar_participant_user (user_id, event_id)
);

CREATE TABLE IF NOT EXISTS calendar_resources (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    facility_id BIGINT UNSIGNED NULL,
    resource_type VARCHAR(30) NOT NULL,
    name VARCHAR(150) NOT NULL,
    description VARCHAR(500) NULL,
    capacity INT UNSIGNED NULL,
    color CHAR(7) NOT NULL DEFAULT '#0f9f6e',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX calendar_resources_facility (facility_id, active),
    UNIQUE KEY calendar_resource_name (facility_id, resource_type, name)
);

CREATE TABLE IF NOT EXISTS calendar_event_resources (
    event_id BIGINT UNSIGNED NOT NULL,
    resource_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (event_id, resource_id),
    INDEX calendar_event_resource_lookup (resource_id, event_id)
);

CREATE TABLE IF NOT EXISTS calendar_reminders (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    offset_minutes INT UNSIGNED NOT NULL,
    channel VARCHAR(30) NOT NULL,
    scheduled_at DATETIME NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    locked_at DATETIME NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY calendar_reminder_unique (event_id, offset_minutes, channel),
    INDEX calendar_reminder_queue (status, scheduled_at)
);

CREATE TABLE IF NOT EXISTS calendar_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    event_id BIGINT UNSIGNED NULL,
    channel VARCHAR(30) NOT NULL DEFAULT 'internal',
    title VARCHAR(180) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    action_url VARCHAR(500) NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX calendar_notifications_user (user_id, read_at, created_at)
);

CREATE TABLE IF NOT EXISTS calendar_integration_settings (
    plugin_slug VARCHAR(100) NOT NULL,
    subject_type VARCHAR(20) NOT NULL DEFAULT 'installation',
    subject_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    encrypted_settings LONGTEXT NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 0,
    last_verified_at DATETIME NULL,
    last_error VARCHAR(500) NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (plugin_slug, subject_type, subject_id)
);

CREATE TABLE IF NOT EXISTS calendar_external_events (
    plugin_slug VARCHAR(100) NOT NULL,
    account_key VARCHAR(191) NOT NULL,
    external_id VARCHAR(255) NOT NULL,
    event_id BIGINT UNSIGNED NULL,
    external_etag VARCHAR(255) NULL,
    sync_token TEXT NULL,
    last_synced_at DATETIME NULL,
    PRIMARY KEY (plugin_slug, account_key, external_id),
    INDEX calendar_external_event (event_id)
);

CREATE TABLE IF NOT EXISTS calendar_sync_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(20) NOT NULL DEFAULT 'upsert',
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    available_at DATETIME NOT NULL,
    last_error VARCHAR(500) NULL,
    locked_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    INDEX calendar_sync_queue (status, available_at),
    INDEX calendar_sync_event (event_id, status)
);

INSERT IGNORE INTO permissions (slug, name, group_key, description) VALUES
('calendar.view', 'View calendar', 'Calendar', 'View events, participants and assigned resources.'),
('calendar.manage', 'Manage calendar', 'Calendar', 'Create and update events and reminders.'),
('calendar.override-conflicts', 'Override calendar conflicts', 'Calendar', 'Confirm a schedule despite a documented warning.'),
('calendar.resources.manage', 'Manage calendar resources', 'Calendar', 'Manage rooms, equipment and other bookable resources.'),
('calendar.settings.manage', 'Manage calendar integrations', 'Calendar', 'Configure notification and external calendar integrations.');

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.slug = 'owner' AND p.slug LIKE 'calendar.%';
