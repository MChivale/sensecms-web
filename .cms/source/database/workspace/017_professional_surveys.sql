CREATE TABLE IF NOT EXISTS surveys (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uid CHAR(36) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    status ENUM('draft','scheduled','published','closed','archived') NOT NULL DEFAULT 'draft',
    response_mode ENUM('anonymous','identified') NOT NULL DEFAULT 'anonymous',
    one_response TINYINT(1) NOT NULL DEFAULT 0,
    captcha_enabled TINYINT(1) NOT NULL DEFAULT 1,
    rate_limit SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    opens_at DATETIME NULL,
    closes_at DATETIME NULL,
    retention_days SMALLINT UNSIGNED NOT NULL DEFAULT 365,
    anonymize_after_days SMALLINT UNSIGNED NOT NULL DEFAULT 90,
    created_by BIGINT UNSIGNED NULL,
    updated_by BIGINT UNSIGNED NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    archived_at DATETIME NULL,
    UNIQUE KEY survey_uid (uid),
    UNIQUE KEY survey_slug (slug),
    INDEX survey_lifecycle (status,opens_at,closes_at,archived_at)
);

CREATE TABLE IF NOT EXISTS survey_translations (
    survey_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(20) NOT NULL,
    title VARCHAR(255) NOT NULL,
    introduction TEXT NULL,
    success_message VARCHAR(1000) NULL,
    closed_message VARCHAR(1000) NULL,
    PRIMARY KEY (survey_id,locale)
);

CREATE TABLE IF NOT EXISTS survey_pages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    survey_id BIGINT UNSIGNED NOT NULL,
    uid CHAR(36) NOT NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    UNIQUE KEY survey_page_uid (uid),
    INDEX survey_page_order (survey_id,sort_order)
);

CREATE TABLE IF NOT EXISTS survey_page_translations (
    page_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(20) NOT NULL,
    title VARCHAR(255) NULL,
    description VARCHAR(1000) NULL,
    PRIMARY KEY (page_id,locale)
);

CREATE TABLE IF NOT EXISTS survey_questions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    survey_id BIGINT UNSIGNED NOT NULL,
    page_id BIGINT UNSIGNED NOT NULL,
    uid CHAR(36) NOT NULL,
    type VARCHAR(30) NOT NULL,
    required TINYINT(1) NOT NULL DEFAULT 0,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    settings JSON NULL,
    UNIQUE KEY survey_question_uid (uid),
    INDEX survey_question_order (survey_id,page_id,sort_order)
);

CREATE TABLE IF NOT EXISTS survey_question_translations (
    question_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(20) NOT NULL,
    label VARCHAR(500) NOT NULL,
    help_text VARCHAR(1000) NULL,
    options JSON NULL,
    PRIMARY KEY (question_id,locale)
);

CREATE TABLE IF NOT EXISTS survey_logic_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    survey_id BIGINT UNSIGNED NOT NULL,
    source_question_id BIGINT UNSIGNED NOT NULL,
    operator VARCHAR(30) NOT NULL,
    compare_value VARCHAR(500) NULL,
    action VARCHAR(30) NOT NULL,
    target_question_id BIGINT UNSIGNED NULL,
    target_page_id BIGINT UNSIGNED NULL,
    sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    INDEX survey_logic (survey_id,source_question_id,sort_order)
);

CREATE TABLE IF NOT EXISTS survey_facilities (
    survey_id BIGINT UNSIGNED NOT NULL,
    facility_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (survey_id,facility_id),
    INDEX survey_facility_target (facility_id,survey_id)
);

CREATE TABLE IF NOT EXISTS survey_target_pages (
    survey_id BIGINT UNSIGNED NOT NULL,
    page_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (survey_id,page_id),
    INDEX survey_page_target (page_id,survey_id)
);

CREATE TABLE IF NOT EXISTS survey_responses (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uid CHAR(36) NOT NULL,
    survey_id BIGINT UNSIGNED NOT NULL,
    facility_id BIGINT UNSIGNED NULL,
    locale VARCHAR(20) NOT NULL,
    status ENUM('started','completed','disqualified','abandoned') NOT NULL DEFAULT 'started',
    respondent_name VARCHAR(190) NULL,
    respondent_email VARCHAR(190) NULL,
    token_hash CHAR(64) NULL,
    visitor_hash CHAR(64) NULL,
    metadata JSON NULL,
    started_at DATETIME NOT NULL,
    completed_at DATETIME NULL,
    anonymized_at DATETIME NULL,
    UNIQUE KEY survey_response_uid (uid),
    INDEX survey_response_stats (survey_id,status,completed_at),
    INDEX survey_response_visitor (survey_id,visitor_hash,completed_at),
    INDEX survey_response_facility (facility_id,survey_id,completed_at)
);

CREATE TABLE IF NOT EXISTS survey_answers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    response_id BIGINT UNSIGNED NOT NULL,
    question_id BIGINT UNSIGNED NOT NULL,
    value JSON NULL,
    text_value TEXT NULL,
    numeric_value DECIMAL(12,4) NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY survey_answer_once (response_id,question_id),
    INDEX survey_answer_stats (question_id,numeric_value)
);

INSERT INTO permissions (slug,name,group_key,description) VALUES
('surveys.view','View surveys','Surveys','View survey campaigns and aggregate results within assigned facilities.'),
('surveys.manage','Manage surveys','Surveys','Create and edit multilingual surveys, targeting and conditional logic.'),
('surveys.publish','Publish surveys','Surveys','Publish, schedule, close and archive survey campaigns.'),
('surveys.responses','View survey responses','Surveys','Read individual survey responses within assigned facilities.'),
('surveys.export','Export survey data','Surveys','Export survey responses and analytical reports.'),
('surveys.anonymize','Manage survey privacy','Surveys','Configure retention and run response anonymisation.')
ON DUPLICATE KEY UPDATE name=VALUES(name),group_key=VALUES(group_key),description=VALUES(description);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='owner' AND p.slug LIKE 'surveys.%';
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r INNER JOIN permissions p ON p.slug LIKE 'surveys.%' WHERE r.slug='administrator';
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r INNER JOIN permissions p ON p.slug IN ('surveys.view','surveys.manage','surveys.publish','surveys.responses','surveys.export') WHERE r.slug='content-manager';
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r INNER JOIN permissions p ON p.slug IN ('surveys.view','surveys.responses','surveys.export') WHERE r.slug='customer-support';

INSERT INTO settings (`key`,value,updated_at) VALUES ('extension_states',JSON_OBJECT('surveys',TRUE),NOW())
ON DUPLICATE KEY UPDATE value=JSON_SET(COALESCE(NULLIF(value,''),JSON_OBJECT()),'$.surveys',COALESCE(JSON_EXTRACT(value,'$.surveys'),TRUE)),updated_at=NOW();
