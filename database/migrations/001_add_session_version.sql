-- ============================================================
-- PixelHop - Migration 001: Add users.session_version
-- ============================================================
-- Tanggal    : 2026-09-10
-- Tujuan     : Session revocation (D1-01).
--              Menambahkan kolom `session_version` ke tabel `users`
--              agar middleware dapat membandingkan versi sesi yang
--              tersimpan di sesi pengguna dengan nilai terkini.
--              Nilai di-increment saat admin suspend/lock/demote atau
--              user mengganti password, sehingga sesi lama menjadi
--              invalid.
-- Cara pakai : mysql -u USER -p DBNAME < database/migrations/001_add_session_version.sql
-- Idempotent : AMAN dijalankan berulang. Guard information_schema
--              memastikan ALTER TABLE hanya dieksekusi bila kolom
--              belum ada. Cocok untuk MySQL 5.7+ / MariaDB.
-- ============================================================

SET @c := (
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'users'
      AND COLUMN_NAME = 'session_version'
);

SET @s := IF(
    @c = 0,
    'ALTER TABLE users ADD COLUMN session_version INT UNSIGNED NOT NULL DEFAULT 1 AFTER daily_reset_at',
    'SELECT 1'
);

PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
