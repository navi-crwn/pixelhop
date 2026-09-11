-- ============================================================
-- PixelHop - Migration 004: Create pending_operations table
-- ============================================================
-- Tanggal    : 2026-09-11
-- Tujuan     : Reliability Fase 2 - tabel jurnal saga upload.
--              Menyimpan state machine operasi multi-langkah
--              (upload/delete/tool) agar bisa di-replay atau
--              di-recover tanpa bergantung pada state in-memory.
--              state: started -> s3_uploaded -> metadata_saved ->
--              completed | failed. created_at memakai epoch INT
--              (konsisten dengan kode existing), updated_at
--              DATETIME.
-- Cara pakai : mysql -u USER -p pixelhop < database/migrations/004_pending_operations.sql
-- Idempotent : AMAN dijalankan berulang. CREATE TABLE IF NOT
--              EXISTS memastikan tabel hanya dibuat sekali; bila
--              tabel sudah ada, pernyataan dilewati tanpa error.
--              Cocok untuk MySQL 5.7+ / MariaDB.
-- ============================================================

CREATE TABLE IF NOT EXISTS `pending_operations` (
  `id`            bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_id`  varchar(64)  NOT NULL COMMENT 'mis. image_id untuk upload',
  `type`          varchar(32)  NOT NULL COMMENT 'upload|delete|tool',
  `state`         enum('started','s3_uploaded','metadata_saved','completed','failed') NOT NULL DEFAULT 'started',
  `payload`       json         DEFAULT NULL COMMENT 's3_keys, providers, size, user_id',
  `attempts`      tinyint unsigned NOT NULL DEFAULT 0,
  `last_error`    varchar(500) DEFAULT NULL,
  `created_at`    int(10) unsigned NOT NULL COMMENT 'epoch',
  `updated_at`    datetime     DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uidx_operation_id` (`operation_id`),
  KEY `idx_state_created` (`state`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
