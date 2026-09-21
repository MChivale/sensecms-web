CREATE TABLE IF NOT EXISTS ai_knowledge_sync_queue (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_type ENUM('page','post') NOT NULL,
    source_id BIGINT UNSIGNED NOT NULL,
    status ENUM('queued','processing','failed') NOT NULL DEFAULT 'queued',
    available_at DATETIME NOT NULL,
    attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
    last_error VARCHAR(500) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY ai_knowledge_sync_source (source_type,source_id),
    INDEX ai_knowledge_sync_due (status,available_at,id)
);

ALTER TABLE ai_training_jobs ADD COLUMN IF NOT EXISTS dataset_checksum CHAR(64) NULL AFTER base_model;
ALTER TABLE ai_training_jobs ADD COLUMN IF NOT EXISTS training_file_path VARCHAR(500) NULL AFTER dataset_checksum;
ALTER TABLE ai_training_jobs ADD COLUMN IF NOT EXISTS example_count INT UNSIGNED NOT NULL DEFAULT 0 AFTER training_file_path;
ALTER TABLE ai_training_jobs ADD COLUMN IF NOT EXISTS estimated_tokens BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER example_count;
ALTER TABLE ai_training_jobs ADD COLUMN IF NOT EXISTS prepared_at DATETIME NULL AFTER estimated_tokens;
