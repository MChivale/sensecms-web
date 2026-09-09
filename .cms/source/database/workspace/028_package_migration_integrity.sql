ALTER TABLE extension_migrations ADD COLUMN IF NOT EXISTS up_checksum CHAR(64) NULL;
ALTER TABLE extension_migrations ADD COLUMN IF NOT EXISTS down_checksum CHAR(64) NULL;
