-- ================================
-- R2 Storage Tracking Table
-- Add to existing schema for hybrid R2 + Contabo storage
-- ================================
--
-- ⚠️  DEPRECATED — DO NOT RUN THIS FILE.
-- Definisi tabel `storage_stats` dan `image_storage` di bawah ini sudah
-- terserap ke `database/schema.sql` (canonical). Gunakan schema.sql sebagai
-- satu-satunya sumber kebenaran skema. File ini dipertahankan hanya sebagai
-- referensi historis dan TIDAK dihapus.
-- ================================

-- Storage usage tracking per provider
CREATE TABLE IF NOT EXISTS storage_stats (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    provider VARCHAR(32) NOT NULL UNIQUE COMMENT 'r2, contabo, local',
    total_bytes BIGINT UNSIGNED DEFAULT 0,
    file_count INT UNSIGNED DEFAULT 0,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_provider (provider)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Initialize providers
INSERT IGNORE INTO storage_stats (provider, total_bytes, file_count) VALUES 
    ('r2', 0, 0),
    ('contabo', 0, 0);

-- Image storage tracking (which provider each image uses)
CREATE TABLE IF NOT EXISTS image_storage (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    image_id VARCHAR(32) NOT NULL,
    size_type ENUM('original', 'large', 'medium', 'thumb') NOT NULL,
    provider VARCHAR(32) NOT NULL COMMENT 'r2, contabo',
    s3_key VARCHAR(512) NOT NULL,
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_image_size (image_id, size_type),
    INDEX idx_provider (provider),
    INDEX idx_image_id (image_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Optional: Add provider column to existing usage_logs if needed
-- ALTER TABLE usage_logs ADD COLUMN storage_provider VARCHAR(32) NULL AFTER tool_name;
