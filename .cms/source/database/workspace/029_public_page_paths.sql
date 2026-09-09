ALTER TABLE pages ADD COLUMN IF NOT EXISTS public_path VARCHAR(255) CHARACTER SET ascii COLLATE ascii_bin NULL;
CREATE UNIQUE INDEX IF NOT EXISTS pages_public_path_unique ON pages (public_path);
