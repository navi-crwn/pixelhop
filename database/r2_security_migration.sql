-- ================================
-- PixelHop - R2 & Security Tables Migration
-- DEPRECATED: canonical schema now lives in database/schema.sql
-- (blocked_ips uses `expires_at`, matching the live DB).
-- Run: mysql -u pixelhop -p pixelhop < database/r2_security_migration.sql
-- ================================

-- R2 Storage Tracking
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

-- R2 Operations Rate Limiting
CREATE TABLE IF NOT EXISTS r2_operations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation_class ENUM('A', 'B') NOT NULL COMMENT 'A=write, B=read',
    operation_type VARCHAR(32) NOT NULL,
    file_key VARCHAR(512) NULL,
    file_size INT UNSIGNED DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_class_date (operation_class, created_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security: Blocked IPs
CREATE TABLE IF NOT EXISTS blocked_ips (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    reason VARCHAR(255) NOT NULL,
    expires_at DATETIME NULL COMMENT 'NULL = permanent',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_ip (ip_address),
    INDEX idx_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security: Events Log
CREATE TABLE IF NOT EXISTS security_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    event_type VARCHAR(32) NOT NULL COMMENT 'bad_bot, suspicious_pattern, rate_limited, ip_blocked',
    details TEXT NULL,
    user_agent VARCHAR(512) NULL,
    request_uri VARCHAR(512) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_ip_type (ip_address, event_type),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Security: IP Request Tracking (for rate limiting)
CREATE TABLE IF NOT EXISTS ip_requests (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    request_path VARCHAR(255) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_ip_time (ip_address, created_at),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================
-- Firewall Settings (add to site_settings if not exists)
-- ================================
INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_type, description) VALUES
    ('firewall_enabled', '1', 'bool', 'Enable security firewall'),
    ('firewall_block_bad_bots', '1', 'bool', 'Block known bad bots'),
    ('firewall_rate_limit_enabled', '1', 'bool', 'Enable rate limiting'),
    ('firewall_rate_limit_requests', '100', 'int', 'Max requests per minute per IP'),
    ('firewall_rate_limit_uploads', '20', 'int', 'Max uploads per hour per IP'),
    ('firewall_auto_block_threshold', '10', 'int', 'Suspicious events before auto-block'),
    ('firewall_auto_block_duration', '24', 'int', 'Auto-block duration in hours');
