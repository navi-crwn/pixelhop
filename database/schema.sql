-- ============================================================
-- PixelHop - Canonical Database Schema
-- Canonical schema, disinkronkan dari produksi 2026-09-10
--
-- Sumber : live dump /tmp/pichost_baseline/pixelhop_schema_only.sql
-- Status : D5-23 - repo schema matches production (15 tabel)
-- Cara   : mysql -u USER -p pixelhop < database/schema.sql
--          (skema canonical untuk install baru; DROP TABLE IF
--           EXISTS disertakan agar dapat dijalankan ulang)
-- ============================================================

CREATE DATABASE IF NOT EXISTS pixelhop
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE pixelhop;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ------------------------------------------------------
-- Table structure for table `abuse_logs`
-- ------------------------------------------------------

DROP TABLE IF EXISTS `abuse_logs`;
CREATE TABLE `abuse_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `abuse_type` varchar(50) NOT NULL,
  `severity` enum('low','medium','high','critical') DEFAULT 'low',
  `details` text DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_type` (`abuse_type`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------
-- Table structure for table `blocked_ips`
-- ------------------------------------------------------

DROP TABLE IF EXISTS `blocked_ips`;
CREATE TABLE `blocked_ips` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `reason` varchar(255) DEFAULT NULL,
  `blocked_by` enum('auto','admin') DEFAULT 'auto',
  `expires_at` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `ip_address` (`ip_address`),
  KEY `idx_ip` (`ip_address`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------
-- Table structure for table `image_storage`
-- ------------------------------------------------------

DROP TABLE IF EXISTS `image_storage`;
CREATE TABLE `image_storage` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `image_id` varchar(32) NOT NULL,
  `size_type` enum('original','large','medium','thumb') NOT NULL,
  `provider` varchar(32) NOT NULL COMMENT 'r2, contabo',
  `s3_key` varchar(512) NOT NULL,
  `file_size` int(10) unsigned NOT NULL DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_image_size` (`image_id`,`size_type`),
  KEY `idx_provider` (`provider`),
  KEY `idx_image_id` (`image_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Table structure for table `ip_requests`
-- ------------------------------------------------------

DROP TABLE IF EXISTS `ip_requests`;
CREATE TABLE `ip_requests` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `request_path` varchar(255) NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip_time` (`ip_address`,`created_at`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=300 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------
-- Table structure for table `login_attempts`
-- ------------------------------------------------------

DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `email` varchar(255) DEFAULT NULL,
  `success` tinyint(1) DEFAULT 0,
  `attempted_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip_address` (`ip_address`),
  KEY `idx_attempted_at` (`attempted_at`)
) ENGINE=InnoDB AUTO_INCREMENT=66 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Table structure for table `r2_operations`
-- ------------------------------------------------------

DROP TABLE IF EXISTS `r2_operations`;
CREATE TABLE `r2_operations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `operation_class` enum('A','B') NOT NULL,
  `operation_type` varchar(32) NOT NULL,
  `file_key` varchar(512) DEFAULT NULL,
  `file_size` int(10) unsigned DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_class_date` (`operation_class`,`created_at`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=282 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------
-- Table structure for table `rate_limits`
-- ------------------------------------------------------

DROP TABLE IF EXISTS `rate_limits`;
CREATE TABLE `rate_limits` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `identifier` varchar(64) NOT NULL COMMENT 'IP or user_id',
  `action` varchar(32) NOT NULL COMMENT 'upload, convert, ocr, etc',
  `tokens` int(10) unsigned DEFAULT 10,
  `last_refill` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_rate_limit` (`identifier`,`action`),
  KEY `idx_last_refill` (`last_refill`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Table structure for table `security_events`
-- ------------------------------------------------------

DROP TABLE IF EXISTS `security_events`;
CREATE TABLE `security_events` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `ip_address` varchar(45) NOT NULL,
  `event_type` varchar(32) NOT NULL,
  `details` text DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `request_uri` varchar(512) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_ip_type` (`ip_address`,`event_type`),
  KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB AUTO_INCREMENT=34 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ------------------------------------------------------
-- Table structure for table `site_settings`
-- ------------------------------------------------------

DROP TABLE IF EXISTS `site_settings`;
CREATE TABLE `site_settings` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `setting_key` varchar(100) NOT NULL,
  `setting_value` text DEFAULT NULL,
  `setting_type` enum('int','bool','string','json') DEFAULT 'string',
  `description` varchar(255) DEFAULT NULL,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `setting_key` (`setting_key`)
) ENGINE=InnoDB AUTO_INCREMENT=81 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Table structure for table `storage_stats`
-- ------------------------------------------------------

DROP TABLE IF EXISTS `storage_stats`;
CREATE TABLE `storage_stats` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `provider` varchar(32) NOT NULL COMMENT 'r2, contabo, local',
  `total_bytes` bigint(20) unsigned DEFAULT 0,
  `file_count` int(10) unsigned DEFAULT 0,
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `provider` (`provider`),
  KEY `idx_provider` (`provider`)
) ENGINE=InnoDB AUTO_INCREMENT=573 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Table structure for table `temp_files`
-- ------------------------------------------------------

DROP TABLE IF EXISTS `temp_files`;
CREATE TABLE `temp_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `file_id` varchar(32) DEFAULT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT 0,
  `tool_name` varchar(50) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `file_id` (`file_id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_expires_at` (`expires_at`),
  KEY `idx_temp_files_file_id` (`file_id`),
  CONSTRAINT `temp_files_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Table structure for table `usage_logs`
-- ------------------------------------------------------

DROP TABLE IF EXISTS `usage_logs`;
CREATE TABLE `usage_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `tool_name` varchar(50) NOT NULL,
  `file_size` bigint(20) unsigned DEFAULT 0,
  `processing_time_ms` int(10) unsigned DEFAULT 0,
  `status` enum('success','failed','quota_exceeded','server_busy') DEFAULT 'success',
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_tool_date` (`user_id`,`tool_name`,`created_at`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `usage_logs_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Table structure for table `user_files`
-- ------------------------------------------------------

DROP TABLE IF EXISTS `user_files`;
CREATE TABLE `user_files` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `file_path` varchar(500) NOT NULL,
  `file_name` varchar(255) NOT NULL,
  `original_name` varchar(255) DEFAULT NULL,
  `file_size` bigint(20) unsigned DEFAULT 0,
  `mime_type` varchar(100) DEFAULT NULL,
  `width` int(10) unsigned DEFAULT NULL,
  `height` int(10) unsigned DEFAULT NULL,
  `is_public` tinyint(1) DEFAULT 1,
  `view_count` bigint(20) unsigned DEFAULT 0,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `user_files_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Table structure for table `user_sessions`
-- ------------------------------------------------------

DROP TABLE IF EXISTS `user_sessions`;
CREATE TABLE `user_sessions` (
  `id` varchar(128) NOT NULL,
  `user_id` int(10) unsigned NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `payload` text DEFAULT NULL,
  `last_activity` int(10) unsigned NOT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_last_activity` (`last_activity`),
  CONSTRAINT `user_sessions_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------
-- Table structure for table `users`
-- ------------------------------------------------------

DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `email` varchar(255) NOT NULL,
  `google_id` varchar(255) DEFAULT NULL,
  `avatar_url` varchar(500) DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `two_factor_secret` varchar(100) DEFAULT NULL,
  `two_factor_enabled` tinyint(1) DEFAULT 0,
  `delete_requested_at` datetime DEFAULT NULL,
  `delete_token` varchar(100) DEFAULT NULL,
  `password_hash` varchar(255) NOT NULL COMMENT 'Argon2id hash',
  `role` enum('user','admin') DEFAULT 'user',
  `account_type` enum('guest','free','premium') DEFAULT 'free',
  `storage_used` bigint(20) unsigned DEFAULT 0 COMMENT 'Bytes used',
  `storage_limit` bigint(20) unsigned DEFAULT 1073741824 COMMENT 'Default 1GB',
  `daily_ocr_count` int(10) unsigned DEFAULT 0,
  `daily_removebg_count` int(10) unsigned DEFAULT 0,
  `daily_reset_at` date DEFAULT NULL,
  `session_version` int(10) unsigned NOT NULL DEFAULT 1,
  `is_blocked` tinyint(1) DEFAULT 0,
  `account_status` enum('active','locked','suspended') DEFAULT 'active',
  `status_reason` varchar(500) DEFAULT NULL,
  `status_updated_at` datetime DEFAULT NULL,
  `status_updated_by` int(10) unsigned DEFAULT NULL,
  `suspend_until` datetime DEFAULT NULL,
  `warning_message` text DEFAULT NULL,
  `warning_shown` tinyint(1) DEFAULT 0,
  `block_reason` varchar(255) DEFAULT NULL,
  `email_verified` tinyint(1) DEFAULT 0,
  `verification_token` varchar(64) DEFAULT NULL,
  `reset_token` varchar(64) DEFAULT NULL,
  `reset_expires` datetime DEFAULT NULL,
  `upload_token` varchar(64) DEFAULT NULL,
  `last_login` datetime DEFAULT NULL,
  `login_attempts` tinyint(3) unsigned DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `created_at` datetime DEFAULT current_timestamp(),
  `updated_at` datetime DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `email_verification_token` varchar(64) DEFAULT NULL,
  `email_verification_expires` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`),
  UNIQUE KEY `upload_token` (`upload_token`),
  KEY `idx_email` (`email`),
  KEY `idx_role` (`role`),
  KEY `idx_is_blocked` (`is_blocked`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_account_status` (`account_status`),
  KEY `idx_upload_token` (`upload_token`)
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
