DELETE rp FROM role_permissions rp INNER JOIN permissions p ON p.id = rp.permission_id WHERE p.slug LIKE 'social.%';
DELETE FROM permissions WHERE slug LIKE 'social.%';
DROP TABLE IF EXISTS social_deliveries;
DROP TABLE IF EXISTS social_post_targets;
DROP TABLE IF EXISTS social_connections;
