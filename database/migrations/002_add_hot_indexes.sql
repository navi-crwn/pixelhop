-- ============================================================
-- PixelHop - Migration 002: Add hot indexes
-- ============================================================
-- Tanggal    : 2026-09-11
-- Tujuan     : Reliability Fase 1 - menambah index untuk query
--              paling sering dijalankan (query panas):
--              - Kuota AI           : usage_logs (user_id, tool_name, created_at)
--              - Firewall rate-limit: ip_requests, security_events (ip_address, created_at)
--              - Abuse              : abuse_logs (ip_address, created_at)
--              - Login              : login_attempts (ip_address, attempted_at)
--              - Blocked IP         : blocked_ips (ip_address)
-- Cara pakai : mysql -u USER -p DBNAME < database/migrations/002_add_hot_indexes.sql
-- Idempotent : AMAN dijalankan 2x / berulang. Setiap index dijaga
--              oleh guard information_schema.STATISTICS + PREPARE/
--              EXECUTE, sehingga ALTER TABLE hanya dieksekusi bila
--              index belum ada. Tabel opsional (abuse_logs, blocked_ips)
--              juga dijaga dengan cek information_schema.TABLES.
--              Cocok untuk MySQL 5.7+ / MariaDB.
-- ============================================================

-- ------------------------------------------------------------
-- 1. usage_logs: idx_user_tool_date (user_id, tool_name, created_at)
-- ------------------------------------------------------------
SET @c := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'usage_logs'
      AND INDEX_NAME = 'idx_user_tool_date'
);
SET @s := IF(
    @c = 0,
    'ALTER TABLE usage_logs ADD INDEX idx_user_tool_date (user_id, tool_name, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2. usage_logs: idx_created_at (created_at)
-- ------------------------------------------------------------
SET @c := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'usage_logs'
      AND INDEX_NAME = 'idx_created_at'
);
SET @s := IF(
    @c = 0,
    'ALTER TABLE usage_logs ADD INDEX idx_created_at (created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3. ip_requests: idx_ip_time (ip_address, created_at)
--    CATATAN: mungkin sudah ada dari DDL lama; guard menanganinya.
-- ------------------------------------------------------------
SET @c := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'ip_requests'
      AND INDEX_NAME = 'idx_ip_time'
);
SET @s := IF(
    @c = 0,
    'ALTER TABLE ip_requests ADD INDEX idx_ip_time (ip_address, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 4. security_events: idx_ip_time (ip_address, created_at)
-- ------------------------------------------------------------
SET @c := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'security_events'
      AND INDEX_NAME = 'idx_ip_time'
);
SET @s := IF(
    @c = 0,
    'ALTER TABLE security_events ADD INDEX idx_ip_time (ip_address, created_at)',
    'SELECT 1'
);
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 5. login_attempts: idx_ip_time (ip_address, attempted_at)
-- ------------------------------------------------------------
SET @c := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'login_attempts'
      AND INDEX_NAME = 'idx_ip_time'
);
SET @s := IF(
    @c = 0,
    'ALTER TABLE login_attempts ADD INDEX idx_ip_time (ip_address, attempted_at)',
    'SELECT 1'
);
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 6. abuse_logs: idx_ip_time (ip_address, created_at)
--    Guard keberadaan tabel + guard keberadaan index.
-- ------------------------------------------------------------
SET @t := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'abuse_logs'
);
SET @c := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'abuse_logs'
      AND INDEX_NAME = 'idx_ip_time'
);
SET @s := IF(
    @t = 0,
    'SELECT ''abuse_logs: table missing, skip'' AS note',
    IF(
        @c = 0,
        'ALTER TABLE abuse_logs ADD INDEX idx_ip_time (ip_address, created_at)',
        'SELECT 1'
    )
);
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 7. blocked_ips: idx_ip (ip_address)
--    Guard keberadaan tabel + guard keberadaan index.
-- ------------------------------------------------------------
SET @t := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'blocked_ips'
);
SET @c := (
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'blocked_ips'
      AND INDEX_NAME = 'idx_ip'
);
SET @s := IF(
    @t = 0,
    'SELECT ''blocked_ips: table missing, skip'' AS note',
    IF(
        @c = 0,
        'ALTER TABLE blocked_ips ADD INDEX idx_ip (ip_address)',
        'SELECT 1'
    )
);
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
