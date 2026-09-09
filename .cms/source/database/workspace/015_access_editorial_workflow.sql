ALTER TABLE users ADD COLUMN IF NOT EXISTS job_title VARCHAR(120) NULL AFTER avatar_url;
ALTER TABLE users ADD COLUMN IF NOT EXISTS last_login_at DATETIME NULL AFTER job_title;
ALTER TABLE roles ADD COLUMN IF NOT EXISTS description VARCHAR(500) NULL AFTER name;
ALTER TABLE roles ADD COLUMN IF NOT EXISTS color CHAR(7) NOT NULL DEFAULT '#2563eb' AFTER description;
ALTER TABLE roles ADD COLUMN IF NOT EXISTS is_system TINYINT(1) NOT NULL DEFAULT 0 AFTER color;
ALTER TABLE roles ADD COLUMN IF NOT EXISTS active TINYINT(1) NOT NULL DEFAULT 1 AFTER is_system;
ALTER TABLE roles ADD COLUMN IF NOT EXISTS created_at DATETIME NULL AFTER active;
ALTER TABLE roles ADD COLUMN IF NOT EXISTS updated_at DATETIME NULL AFTER created_at;
ALTER TABLE permissions ADD COLUMN IF NOT EXISTS group_key VARCHAR(80) NOT NULL DEFAULT 'system' AFTER name;
ALTER TABLE permissions ADD COLUMN IF NOT EXISTS description VARCHAR(500) NULL AFTER group_key;

CREATE TABLE IF NOT EXISTS user_facilities (
    user_id BIGINT UNSIGNED NOT NULL,
    facility_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (user_id, facility_id),
    INDEX user_facility_scope (facility_id, user_id)
);

CREATE TABLE IF NOT EXISTS team_facilities (
    team_id BIGINT UNSIGNED NOT NULL,
    facility_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL,
    PRIMARY KEY (team_id, facility_id),
    INDEX team_facility_scope (facility_id, team_id)
);

ALTER TABLE pages ADD COLUMN IF NOT EXISTS owner_user_id BIGINT UNSIGNED NULL AFTER facility_id;
ALTER TABLE pages ADD COLUMN IF NOT EXISTS assigned_user_id BIGINT UNSIGNED NULL AFTER owner_user_id;
ALTER TABLE pages ADD COLUMN IF NOT EXISTS workflow_state ENUM('draft','in_review','changes_requested','approved') NOT NULL DEFAULT 'draft' AFTER assigned_user_id;
ALTER TABLE pages ADD COLUMN IF NOT EXISTS editorial_note VARCHAR(1000) NULL AFTER workflow_state;
ALTER TABLE pages ADD COLUMN IF NOT EXISTS review_requested_at DATETIME NULL AFTER editorial_note;
ALTER TABLE pages ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL AFTER review_requested_at;
ALTER TABLE pages ADD COLUMN IF NOT EXISTS reviewed_by BIGINT UNSIGNED NULL AFTER reviewed_at;
ALTER TABLE pages ADD INDEX IF NOT EXISTS page_workflow_queue (workflow_state, assigned_user_id, facility_id);

ALTER TABLE posts ADD COLUMN IF NOT EXISTS owner_user_id BIGINT UNSIGNED NULL AFTER facility_id;
ALTER TABLE posts ADD COLUMN IF NOT EXISTS assigned_user_id BIGINT UNSIGNED NULL AFTER owner_user_id;
ALTER TABLE posts ADD COLUMN IF NOT EXISTS workflow_state ENUM('draft','in_review','changes_requested','approved') NOT NULL DEFAULT 'draft' AFTER assigned_user_id;
ALTER TABLE posts ADD COLUMN IF NOT EXISTS editorial_note VARCHAR(1000) NULL AFTER workflow_state;
ALTER TABLE posts ADD COLUMN IF NOT EXISTS review_requested_at DATETIME NULL AFTER editorial_note;
ALTER TABLE posts ADD COLUMN IF NOT EXISTS reviewed_at DATETIME NULL AFTER review_requested_at;
ALTER TABLE posts ADD COLUMN IF NOT EXISTS reviewed_by BIGINT UNSIGNED NULL AFTER reviewed_at;
ALTER TABLE posts ADD INDEX IF NOT EXISTS post_workflow_queue (workflow_state, assigned_user_id, facility_id);

CREATE TABLE IF NOT EXISTS content_workflow_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('page','post') NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    facility_id BIGINT UNSIGNED NOT NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    assigned_user_id BIGINT UNSIGNED NULL,
    from_state VARCHAR(40) NOT NULL,
    to_state VARCHAR(40) NOT NULL,
    action VARCHAR(50) NOT NULL,
    note VARCHAR(1000) NULL,
    created_at DATETIME NOT NULL,
    INDEX workflow_entity (entity_type, entity_id, created_at),
    INDEX workflow_queue (to_state, facility_id, assigned_user_id, created_at)
);

CREATE TABLE IF NOT EXISTS user_notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    type VARCHAR(60) NOT NULL,
    title VARCHAR(180) NOT NULL,
    message VARCHAR(500) NOT NULL,
    url VARCHAR(500) NOT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    INDEX notification_unread (user_id, read_at, created_at)
);

INSERT INTO permissions (slug,name,group_key,description) VALUES
('system.owner','System owner','System','Unrestricted access to the SenseCMS installation.'),
('console.access','Access console','Workspace','Sign in and use the administration workspace.'),
('content.pages.view','View pages','Content','View page records within assigned facilities.'),
('content.pages.edit','Edit pages','Content','Create and edit draft pages and Page Builder sections.'),
('content.pages.review','Review pages','Editorial workflow','Approve pages or request editorial changes.'),
('content.pages.publish','Publish pages','Editorial workflow','Publish, schedule or make pages private.'),
('content.pages.delete','Delete pages','Content','Archive and permanently delete pages.'),
('content.posts.view','View posts','Content','View posts within assigned facilities.'),
('content.posts.edit','Edit posts','Content','Create and edit draft posts.'),
('content.posts.review','Review posts','Editorial workflow','Approve posts or request editorial changes.'),
('content.posts.publish','Publish posts','Editorial workflow','Publish and schedule posts.'),
('content.posts.delete','Delete posts','Content','Archive posts.'),
('content.workflow.view','View workflow','Editorial workflow','Open the editorial queue and history.'),
('content.media.manage','Manage media','Content','Upload and select media assets.'),
('content.navigation.manage','Manage navigation','Content','Edit navigation and categories.'),
('content.seo.manage','Manage SEO','Content','Edit global and localized page SEO.'),
('facilities.view','View facilities','Facilities','View assigned facility profiles.'),
('facilities.manage','Manage facilities','Facilities','Create and configure facility profiles.'),
('forms.view','View form inbox','Engagement','Read form submissions for assigned facilities.'),
('forms.manage','Manage form inbox','Engagement','Update and remove form submissions.'),
('forms.export','Export form submissions','Engagement','Export form submission data.'),
('chat.view','View live chat','Engagement','View and accept live-chat conversations.'),
('chat.manage','Manage live chat','Engagement','Transfer, close and configure conversations.'),
('ai.manage','Manage AI assistant','Engagement','Configure the AI assistant and knowledge.'),
('appearance.manage','Manage appearance','Experience','Configure branding and activate themes.'),
('extensions.manage','Manage extensions','System','Install, update and configure extensions.'),
('system.manage','Manage system','System','Manage cache, sounds, CAPTCHA, languages and licensing.'),
('users.view','View users and teams','Access control','View platform users, teams and role assignments.'),
('users.manage','Manage users and teams','Access control','Create and update users, teams and facility scopes.'),
('roles.manage','Manage roles','Access control','Create roles and assign permissions.'),
('audit.view','View audit log','Access control','Review security and editorial activity.')
ON DUPLICATE KEY UPDATE name=VALUES(name),group_key=VALUES(group_key),description=VALUES(description);

INSERT INTO roles (slug,name,description,color,is_system,active,created_at,updated_at) VALUES
('owner','Owner','Unrestricted platform owner. This role cannot be disabled.','#163f73',1,1,NOW(),NOW()),
('administrator','Administrator','Operational administrator with full site-management access.','#2563eb',1,1,NOW(),NOW()),
('content-manager','Content Manager','Manages multilingual content and publication across assigned facilities.','#7c3aed',1,1,NOW(),NOW()),
('editor','Editor','Creates and submits draft content for review.','#0f766e',1,1,NOW(),NOW()),
('reviewer','Reviewer','Reviews, approves and publishes assigned content.','#b45309',1,1,NOW(),NOW()),
('customer-support','Customer Support','Handles forms and live-chat conversations for assigned facilities.','#be123c',1,1,NOW(),NOW())
ON DUPLICATE KEY UPDATE name=VALUES(name),description=VALUES(description),color=VALUES(color),is_system=VALUES(is_system),active=VALUES(active),updated_at=NOW();

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='owner';
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug='administrator' AND p.slug<>'system.owner';
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r INNER JOIN permissions p ON p.slug IN ('console.access','content.pages.view','content.pages.edit','content.pages.review','content.pages.publish','content.pages.delete','content.posts.view','content.posts.edit','content.posts.review','content.posts.publish','content.posts.delete','content.workflow.view','content.media.manage','content.navigation.manage','content.seo.manage','facilities.view','forms.view','forms.manage','forms.export') WHERE r.slug='content-manager';
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r INNER JOIN permissions p ON p.slug IN ('console.access','content.pages.view','content.pages.edit','content.posts.view','content.posts.edit','content.workflow.view','content.media.manage','facilities.view') WHERE r.slug='editor';
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r INNER JOIN permissions p ON p.slug IN ('console.access','content.pages.view','content.pages.review','content.pages.publish','content.posts.view','content.posts.review','content.posts.publish','content.workflow.view','facilities.view') WHERE r.slug='reviewer';
INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r INNER JOIN permissions p ON p.slug IN ('console.access','facilities.view','forms.view','forms.manage','forms.export','chat.view','chat.manage') WHERE r.slug='customer-support';

UPDATE pages SET owner_user_id=(SELECT id FROM users WHERE active=1 ORDER BY id LIMIT 1) WHERE owner_user_id IS NULL;
UPDATE posts SET owner_user_id=COALESCE(author_id,(SELECT id FROM users WHERE active=1 ORDER BY id LIMIT 1)) WHERE owner_user_id IS NULL;
UPDATE pages SET workflow_state=IF(status IN ('published','scheduled','private'),'approved','draft');
UPDATE posts SET workflow_state=IF(status IN ('published','scheduled'),'approved','draft');
