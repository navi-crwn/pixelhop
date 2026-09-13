-- ============================================================
-- PixelHop - Migration 006: Make usage_logs.user_id nullable
-- ============================================================
-- Tanggal    : 2026-09-13
-- Tujuan     : Allow guest usage_logs rows (user_id = NULL).
--              Production schema shipped usage_logs.user_id NOT NULL
--              with an ON DELETE CASCADE FK, which causes an INSERT
--              failure when palette (and any future guest tool) calls
--              recordLightToolUsage(null, ...).
--
--              Fix:
--                1. Drop the existing FK (usage_logs_ibfk_1).
--                2. MODIFY user_id to allow NULL.
--                3. Re-add the FK with ON DELETE SET NULL so that
--                   rows written by deleted users are retained for
--                   audit/quota purposes (user_id becomes NULL).
--
-- Idempotent : YES. Every destructive step is guarded by a
--              SELECT from information_schema before executing via
--              a prepared statement. Running the migration twice
--              produces no error and no duplicate work.
--
-- Requires   : MySQL 5.7+ / MariaDB 10.2+
-- Usage      : mysql -u USER -p DBNAME < database/migrations/006_usage_logs_user_id_nullable.sql
-- ============================================================

-- ── Step 1: Drop FK if it currently exists ────────────────────
-- We query information_schema.KEY_COLUMN_USAGE to find whether the
-- FK named 'usage_logs_ibfk_1' still exists on this schema. The
-- result drives a prepared ALTER or a harmless no-op SELECT.
SET @fk_exists := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA  = DATABASE()
      AND TABLE_NAME    = 'usage_logs'
      AND CONSTRAINT_NAME = 'usage_logs_ibfk_1'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @drop_fk := IF(
    @fk_exists > 0,
    'ALTER TABLE usage_logs DROP FOREIGN KEY usage_logs_ibfk_1',
    'SELECT ''usage_logs_ibfk_1: FK not present, skip DROP'' AS note'
);

PREPARE stmt FROM @drop_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Step 2: Make user_id nullable if it is currently NOT NULL ──
-- INFORMATION_SCHEMA.COLUMNS.IS_NULLABLE = 'NO' means the column
-- is still NOT NULL and needs to be altered. If a previous run
-- already changed it, this step is skipped.
SET @col_nullable := (
    SELECT IS_NULLABLE
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA  = DATABASE()
      AND TABLE_NAME    = 'usage_logs'
      AND COLUMN_NAME   = 'user_id'
);

-- Build the ALTER only when the column is definitively NOT NULL.
SET @alter_col := IF(
    @col_nullable = 'NO',
    'ALTER TABLE usage_logs MODIFY COLUMN user_id INT(10) UNSIGNED DEFAULT NULL',
    'SELECT ''usage_logs.user_id: already nullable, skip MODIFY'' AS note'
);

PREPARE stmt FROM @alter_col;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ── Step 3: Re-add FK with ON DELETE SET NULL ──────────────────
-- Only add if the FK is absent (covers first run AND cases where
-- Step 1 was a no-op because the FK was never created).
SET @fk_exists_after := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA  = DATABASE()
      AND TABLE_NAME    = 'usage_logs'
      AND CONSTRAINT_NAME = 'usage_logs_ibfk_1'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @add_fk := IF(
    @fk_exists_after = 0,
    'ALTER TABLE usage_logs ADD CONSTRAINT usage_logs_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL',
    'SELECT ''usage_logs_ibfk_1: FK already present, skip ADD'' AS note'
);

PREPARE stmt FROM @add_fk;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
