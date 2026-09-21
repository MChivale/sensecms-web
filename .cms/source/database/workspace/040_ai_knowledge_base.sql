ALTER TABLE ai_knowledge_documents ADD COLUMN IF NOT EXISTS source_key VARCHAR(191) NULL AFTER source_type;
ALTER TABLE ai_knowledge_documents ADD COLUMN IF NOT EXISTS source_id BIGINT UNSIGNED NULL AFTER source_key;
ALTER TABLE ai_knowledge_documents ADD COLUMN IF NOT EXISTS facility_id BIGINT UNSIGNED NULL AFTER source_id;
ALTER TABLE ai_knowledge_documents ADD COLUMN IF NOT EXISTS body MEDIUMTEXT NULL AFTER source_url;
ALTER TABLE ai_knowledge_documents ADD COLUMN IF NOT EXISTS original_name VARCHAR(255) NULL AFTER body;
ALTER TABLE ai_knowledge_documents ADD COLUMN IF NOT EXISTS mime_type VARCHAR(120) NULL AFTER original_name;
ALTER TABLE ai_knowledge_documents ADD COLUMN IF NOT EXISTS storage_path VARCHAR(500) NULL AFTER mime_type;
ALTER TABLE ai_knowledge_documents ADD COLUMN IF NOT EXISTS checksum CHAR(64) NULL AFTER storage_path;
ALTER TABLE ai_knowledge_documents ADD COLUMN IF NOT EXISTS content_checksum CHAR(64) NULL AFTER checksum;
ALTER TABLE ai_knowledge_documents ADD COLUMN IF NOT EXISTS byte_size BIGINT UNSIGNED NOT NULL DEFAULT 0 AFTER content_checksum;
ALTER TABLE ai_knowledge_documents ADD COLUMN IF NOT EXISTS index_status ENUM('pending','ready','failed') NOT NULL DEFAULT 'pending' AFTER status;
ALTER TABLE ai_knowledge_documents ADD COLUMN IF NOT EXISTS index_error VARCHAR(500) NULL AFTER index_status;
ALTER TABLE ai_knowledge_documents ADD COLUMN IF NOT EXISTS indexed_at DATETIME NULL AFTER index_error;
ALTER TABLE ai_knowledge_documents ADD COLUMN IF NOT EXISTS created_by BIGINT UNSIGNED NULL AFTER indexed_at;
ALTER TABLE ai_knowledge_documents ADD COLUMN IF NOT EXISTS updated_by BIGINT UNSIGNED NULL AFTER created_by;
ALTER TABLE ai_knowledge_documents ADD UNIQUE INDEX IF NOT EXISTS ai_knowledge_source_key (source_key);
ALTER TABLE ai_knowledge_documents ADD INDEX IF NOT EXISTS ai_knowledge_status_locale (status,locale,index_status);
ALTER TABLE ai_knowledge_documents ADD INDEX IF NOT EXISTS ai_knowledge_source (source_type,source_id);

ALTER TABLE pages ADD COLUMN IF NOT EXISTS ai_knowledge_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER visibility;
ALTER TABLE posts ADD COLUMN IF NOT EXISTS ai_knowledge_enabled TINYINT(1) NOT NULL DEFAULT 1 AFTER status;

CREATE TABLE IF NOT EXISTS ai_training_datasets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    description VARCHAR(1000) NULL,
    provider_id BIGINT UNSIGNED NULL,
    base_model VARCHAR(150) NULL,
    status ENUM('draft','ready','training','active','archived') NOT NULL DEFAULT 'draft',
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX ai_training_dataset_status (status,updated_at)
);

CREATE TABLE IF NOT EXISTS ai_training_examples (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dataset_id BIGINT UNSIGNED NOT NULL,
    input_text MEDIUMTEXT NOT NULL,
    ideal_output MEDIUMTEXT NOT NULL,
    instructions TEXT NULL,
    locale VARCHAR(20) NULL,
    approved TINYINT(1) NOT NULL DEFAULT 0,
    approved_by BIGINT UNSIGNED NULL,
    approved_at DATETIME NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX ai_training_example_dataset (dataset_id,approved,id)
);

CREATE TABLE IF NOT EXISTS ai_training_jobs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dataset_id BIGINT UNSIGNED NOT NULL,
    provider_id BIGINT UNSIGNED NOT NULL,
    provider_job_id VARCHAR(255) NULL,
    base_model VARCHAR(150) NOT NULL,
    fine_tuned_model VARCHAR(255) NULL,
    status ENUM('prepared','submitted','running','succeeded','failed','cancelled') NOT NULL DEFAULT 'prepared',
    estimated_cost_usd DECIMAL(18,6) NULL,
    actual_cost_usd DECIMAL(18,6) NULL,
    error_code VARCHAR(80) NULL,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX ai_training_job_status (status,updated_at)
);

CREATE TABLE IF NOT EXISTS ai_evaluation_cases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(190) NOT NULL,
    locale VARCHAR(20) NULL,
    question TEXT NOT NULL,
    expected_answer MEDIUMTEXT NOT NULL,
    required_source_key VARCHAR(191) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX ai_evaluation_active (active,locale,id)
);
