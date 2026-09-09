CREATE TABLE IF NOT EXISTS extension_publishers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    key_id VARCHAR(100) NOT NULL UNIQUE,
    name VARCHAR(150) NOT NULL,
    website VARCHAR(500) NULL,
    public_key TEXT NOT NULL,
    fingerprint CHAR(64) NOT NULL UNIQUE,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    INDEX publisher_active(active)
);

CREATE TABLE IF NOT EXISTS extension_packages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(20) NOT NULL,
    slug VARCHAR(100) NOT NULL,
    name VARCHAR(150) NOT NULL,
    version VARCHAR(30) NOT NULL,
    description TEXT NULL,
    publisher VARCHAR(150) NOT NULL,
    publisher_url VARCHAR(500) NULL,
    publisher_key_id VARCHAR(100) NULL,
    source VARCHAR(20) NOT NULL DEFAULT 'package',
    signature_status VARCHAR(20) NOT NULL DEFAULT 'verified',
    active TINYINT(1) NOT NULL DEFAULT 0,
    manifest JSON NOT NULL,
    package_checksum CHAR(64) NULL,
    install_path VARCHAR(500) NULL,
    update_url VARCHAR(500) NULL,
    update_checked_at DATETIME NULL,
    available_version VARCHAR(30) NULL,
    available_package_url VARCHAR(500) NULL,
    last_error VARCHAR(500) NULL,
    installed_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY package_identity(type, slug),
    INDEX package_status(type, active),
    INDEX update_available(available_version)
);

CREATE TABLE IF NOT EXISTS extension_package_releases (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_id BIGINT UNSIGNED NOT NULL,
    version VARCHAR(30) NOT NULL,
    manifest JSON NOT NULL,
    archive_path VARCHAR(500) NOT NULL,
    package_checksum CHAR(64) NULL,
    created_at DATETIME NOT NULL,
    restored_at DATETIME NULL,
    INDEX package_release(package_id, id),
    INDEX release_restored(restored_at)
);

CREATE TABLE IF NOT EXISTS extension_migrations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_type VARCHAR(20) NOT NULL,
    package_slug VARCHAR(100) NOT NULL,
    migration_id VARCHAR(120) NOT NULL,
    package_version VARCHAR(30) NOT NULL,
    down_file VARCHAR(500) NOT NULL,
    applied_at DATETIME NOT NULL,
    rolled_back_at DATETIME NULL,
    UNIQUE KEY package_migration(package_type, package_slug, migration_id),
    INDEX package_version(package_type, package_slug, package_version)
);
