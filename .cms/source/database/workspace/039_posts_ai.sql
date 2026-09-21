INSERT INTO permissions (slug,name,group_key,description) VALUES
('content.posts.ai','Use AI in Posts','Content','Create reviewable AI proposals for posts without saving or publishing automatically.')
ON DUPLICATE KEY UPDATE name=VALUES(name),group_key=VALUES(group_key),description=VALUES(description);

INSERT IGNORE INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.slug IN ('owner','administrator','content-manager','editor') AND p.slug='content.posts.ai';
