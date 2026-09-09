CREATE TABLE IF NOT EXISTS calendar_categories (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(40) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    color CHAR(7) NOT NULL DEFAULT '#2563eb',
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);
INSERT IGNORE INTO calendar_categories (slug,name,color,active,created_at,updated_at)
VALUES ('general','General','#2563eb',1,UTC_TIMESTAMP(),UTC_TIMESTAMP());
INSERT IGNORE INTO calendar_categories (slug,name,color,active,created_at,updated_at)
SELECT DISTINCT event_type,event_type,'#64748b',0,UTC_TIMESTAMP(),UTC_TIMESTAMP()
FROM calendar_events WHERE event_type<>'general';
