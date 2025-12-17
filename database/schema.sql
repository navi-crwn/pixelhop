-- PixelHop - Database Schema
-- Phase 2: Authentication System
-- 
-- Run this SQL to create the users table:
-- mysql -u root -p pixelhop < database/schema.sql

-- Create database if not exists
CREATE DATABASE IF NOT EXISTS pixelhop 
    CHARACTER SET utf8mb4 
    COLLATE utf8mb4_unicode_ci;

USE pixelhop;

-- ================================
-- Users Table
-- ================================
CREATE TABLE IF NOT EXISTS users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(255) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL COMMENT 'Argon2id hash',
    role ENUM('user', 'admin') DEFAULT 'user',
    storage_used BIGINT UNSIGNED DEFAULT 0 COMMENT 'Bytes used',
    storage_limit BIGINT UNSIGNED DEFAULT 1073741824 COMMENT 'Default 1GB',
    is_blocked BOOLEAN DEFAULT FALSE,
    block_reason VARCHAR(255) NULL,
    email_verified BOOLEAN DEFAULT FALSE,
    verification_token VARCHAR(64) NULL,
    reset_token VARCHAR(64) NULL,
    reset_expires DATETIME NULL,
    last_login DATETIME NULL,
    login_attempts TINYINT UNSIGNED DEFAULT 0,
    locked_until DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_is_blocked (is_blocked),
    INDEX idx_created_at (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================
-- User Sessions Table (optional - for DB sessions)
-- ================================
CREATE TABLE IF NOT EXISTS user_sessions (
    id VARCHAR(128) PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255),
    payload TEXT,
    last_activity INT UNSIGNED NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_user_id (user_id),
    INDEX idx_last_activity (last_activity),
    
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================
-- Login Attempts Table (for rate limiting)
-- ================================
CREATE TABLE IF NOT EXISTS login_attempts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip_address VARCHAR(45) NOT NULL,
    email VARCHAR(255) NULL,
    success BOOLEAN DEFAULT FALSE,
    attempted_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_ip_address (ip_address),
    INDEX idx_attempted_at (attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================
-- Rate Limits Table (file-based fallback available)
-- ================================
CREATE TABLE IF NOT EXISTS rate_limits (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(64) NOT NULL COMMENT 'IP or user_id',
    action VARCHAR(32) NOT NULL COMMENT 'upload, convert, ocr, etc',
    tokens INT UNSIGNED DEFAULT 10,
    last_refill DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_rate_limit (identifier, action),
    INDEX idx_last_refill (last_refill)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ================================
-- Create Default Admin User
-- Password: change_me_immediately
-- ================================
-- INSERT INTO users (email, password_hash, role) VALUES 
-- ('admin@p.hel.ink', '$argon2id$v=19$m=65536,t=4,p=1$...', 'admin');
