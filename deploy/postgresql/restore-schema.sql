-- Run once after importing the MariaDB backup into each PostgreSQL database.
BEGIN;
ALTER TABLE user_roles
    ALTER COLUMN user_id TYPE bigint USING user_id::bigint,
    ALTER COLUMN role_id TYPE bigint USING role_id::bigint;
ALTER TABLE user_roles
    ADD CONSTRAINT user_roles_ibfk_1 FOREIGN KEY (user_id) REFERENCES users(id),
    ADD CONSTRAINT user_roles_ibfk_2 FOREIGN KEY (role_id) REFERENCES roles(id);
CREATE EXTENSION IF NOT EXISTS vector;
ALTER TABLE ai_knowledge_chunks
    ALTER COLUMN embedding TYPE vector USING embedding::vector;
COMMIT;
