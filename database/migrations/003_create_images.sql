-- ============================================================
-- PixelHop - Migration 003: Create images metadata table
-- ============================================================
-- Tanggal    : 2026-09-11
-- Tujuan     : Reliability Fase 2 - fondasi skema metadata foto.
--              Membuat tabel `images` sebagai pengganti bertahap
--              data/images.json. Timestamp disimpan sebagai epoch
--              INT (created_at, delete_at, marked_for_deletion,
--              last_viewed_at, deleting_at) agar konsisten dengan
--              kode existing (view.php, cron) yang membandingkan
--              epoch. updated_at tetap DATETIME.
-- Cara pakai : mysql -u USER -p pixelhop < database/migrations/003_create_images.sql
-- Idempotent : AMAN dijalankan berulang. CREATE TABLE IF NOT EXISTS
--              menangani idempotensi pembuatan tabel. FOREIGN KEY
--              dijaga guard information_schema.TABLE_CONSTRAINTS
--              sehingga ADD CONSTRAINT hanya dieksekusi bila FK
--              belum ada (MySQL/MariaDB tidak punya ADD CONSTRAINT
--              IF NOT EXISTS native). Cocok untuk MySQL 5.7+ /
--              MariaDB.
-- ============================================================

-- ------------------------------------------------------------
-- 1. Buat tabel `images` bila belum ada.
--    FK `fk_images_user` ikut dibuat inline saat tabel baru.
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `images` (
  `id`                  varchar(64)  NOT NULL COMMENT 'Public ID (mis. slug_code)',
  `user_id`             int(10) unsigned DEFAULT NULL COMMENT 'NULL = guest',
  `ip`                  varchar(45)  NOT NULL DEFAULT '',
  `filename`            varchar(255) NOT NULL DEFAULT '',
  `mime_type`           varchar(64)  NOT NULL DEFAULT '',
  `extension`           varchar(8)   NOT NULL DEFAULT '',
  `size`                bigint(20) unsigned NOT NULL DEFAULT 0,
  `width`               int(10) unsigned NOT NULL DEFAULT 0,
  `height`              int(10) unsigned NOT NULL DEFAULT 0,
  `hash`                varchar(64)  NOT NULL DEFAULT '' COMMENT 'sha256',
  `urls`                json         DEFAULT NULL COMMENT 'array size=>proxyUrl (legacy fallback)',
  `s3_keys`             json         NOT NULL COMMENT 'array size=>key',
  `storage_providers`   json         DEFAULT NULL COMMENT 'array size=>r2|contabo',
  `view_count`          bigint(20) unsigned NOT NULL DEFAULT 0,
  `last_viewed_at`      int(10) unsigned DEFAULT NULL COMMENT 'epoch',
  `delete_at`           int(10) unsigned DEFAULT NULL COMMENT 'epoch',
  `marked_for_deletion` int(10) unsigned DEFAULT NULL COMMENT 'epoch',
  `deleting_at`         int(10) unsigned DEFAULT NULL COMMENT 'epoch claim marker',
  `last_delete_error`   json         DEFAULT NULL,
  `created_at`          int(10) unsigned NOT NULL COMMENT 'epoch',
  `updated_at`          datetime     DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_hash` (`hash`),
  KEY `idx_user_created` (`user_id`,`created_at`),
  KEY `idx_delete_at` (`delete_at`),
  KEY `idx_marked` (`marked_for_deletion`),
  KEY `idx_created_at` (`created_at`),
  CONSTRAINT `fk_images_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- 2. Guard FOREIGN KEY `fk_images_user`.
--    Menangani kasus tabel `images` sudah ada tetapi FK belum ada
--    (mis. dibuat manual atau dari versi skema lama). Bila FK sudah
--    ada, hanya SELECT 1 sehingga tidak ada duplicate constraint.
-- ------------------------------------------------------------
SET @c := (
    SELECT COUNT(*)
    FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'images'
      AND CONSTRAINT_NAME = 'fk_images_user'
      AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);
SET @s := IF(
    @c = 0,
    'ALTER TABLE images ADD CONSTRAINT fk_images_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
