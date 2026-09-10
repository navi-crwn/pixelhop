-- ================================
-- User Status Migration
-- Add account status columns for lock/suspend/warning
-- Run: mysql -u root -p pixelhop < database/user_status_migration.sql
--
-- DEPRECATED (2026-09-10): isi migrasi ini sudah terserap ke dalam
-- database/schema.sql canonical (disinkronkan dari produksi).
-- Jangan jalankan di database baru/produksi; pertahankan file hanya
-- untuk referensi historis.
-- ================================

-- Add status column: active, locked, suspended
ALTER TABLE users ADD COLUMN account_status ENUM('active', 'locked', 'suspended') DEFAULT 'active' AFTER is_blocked;
ALTER TABLE users ADD COLUMN status_reason VARCHAR(500) NULL AFTER account_status;
ALTER TABLE users ADD COLUMN status_updated_at DATETIME NULL AFTER status_reason;
ALTER TABLE users ADD COLUMN status_updated_by INT UNSIGNED NULL AFTER status_updated_at;
ALTER TABLE users ADD COLUMN suspend_until DATETIME NULL AFTER status_updated_by;
ALTER TABLE users ADD COLUMN warning_message TEXT NULL AFTER suspend_until;
ALTER TABLE users ADD COLUMN warning_shown BOOLEAN DEFAULT FALSE AFTER warning_message;

-- Add index for status filtering
ALTER TABLE users ADD INDEX idx_account_status (account_status);

-- View the changes
-- DESCRIBE users;
