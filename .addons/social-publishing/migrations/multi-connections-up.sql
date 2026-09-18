ALTER TABLE social_connections
    DROP PRIMARY KEY,
    ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (id),
    ADD UNIQUE KEY social_connection_account (plugin_slug, external_account_id),
    ADD INDEX social_connections_plugin (plugin_slug, enabled);

ALTER TABLE social_post_targets
    ADD COLUMN connection_id BIGINT UNSIGNED NULL AFTER plugin_slug;

UPDATE social_post_targets t
INNER JOIN social_connections c ON c.plugin_slug = t.plugin_slug
SET t.connection_id = c.id
WHERE t.connection_id IS NULL;

ALTER TABLE social_post_targets
    DROP PRIMARY KEY,
    ADD COLUMN id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT FIRST,
    ADD PRIMARY KEY (id),
    ADD UNIQUE KEY social_target_connection (post_id, connection_id),
    ADD INDEX social_target_connection_status (connection_id, enabled);

ALTER TABLE social_deliveries
    ADD COLUMN connection_id BIGINT UNSIGNED NULL AFTER plugin_slug,
    ADD COLUMN destination_external_id VARCHAR(191) NULL AFTER connection_id,
    ADD COLUMN destination_display_name VARCHAR(180) NULL AFTER destination_external_id;

UPDATE social_deliveries d
INNER JOIN social_connections c ON c.plugin_slug = d.plugin_slug
SET d.connection_id = c.id,
    d.destination_external_id = c.external_account_id,
    d.destination_display_name = c.display_name
WHERE d.connection_id IS NULL;

ALTER TABLE social_deliveries
    DROP INDEX social_delivery_revision,
    ADD UNIQUE KEY social_delivery_destination_revision (post_id, connection_id, target_revision),
    ADD INDEX social_delivery_connection (connection_id, created_at);

