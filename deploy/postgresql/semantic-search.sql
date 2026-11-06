-- Apply to the selected installation as its database owner after pgvector is enabled.
BEGIN;
ALTER TABLE ai_knowledge_chunks ADD COLUMN IF NOT EXISTS embedding_model text;
CREATE TABLE IF NOT EXISTS ai_semantic_cache (
    model text NOT NULL, content_hash text NOT NULL, embedding vector(1536) NOT NULL,
    PRIMARY KEY (model, content_hash)
);
CREATE TABLE IF NOT EXISTS ai_semantic_usage (
    day date NOT NULL, purpose text NOT NULL, requests integer NOT NULL, input_tokens integer NOT NULL DEFAULT 0,
    PRIMARY KEY (day, purpose)
);
COMMIT;
