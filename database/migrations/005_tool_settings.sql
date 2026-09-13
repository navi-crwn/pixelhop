-- ============================================================
-- PixelHop - Migration 005: Tool settings for new AI & instant tools
-- ============================================================
-- Tanggal    : 2026-09-12
-- Tujuan     : Menyediakan setting di site_settings untuk tool baru
--              (AI HD Upscale, Magic Eraser, Face Blur, Color Palette)
--              agar admin dapat toggle on/off dan mengatur kuota harian:
--                - Toggle on/off:
--                    tool_upscale_enabled, tool_erase_enabled,
--                    tool_faceblur_enabled, tool_palette_enabled
--                  plus memastikan toggle AI lama tetap ada:
--                    tool_ocr_enabled, tool_rembg_enabled
--                - Kuota harian (dibaca API via
--                  $gatekeeper->getSetting(key, default)):
--                    upscale_limit_free / upscale_limit_premium
--                    erase_limit_free   / erase_limit_premium
--                    faceblur_limit_free/ faceblur_limit_premium
--                    palette_limit_guest
-- Cara pakai : mysql -u USER -p DBNAME < database/migrations/005_tool_settings.sql
-- Idempotent : AMAN dijalankan 2x / berulang. Guard information_schema
--              memastikan blok INSERT hanya dieksekusi bila tabel
--              site_settings ada, dan INSERT IGNORE (unique key
--              setting_key) membuat baris yang sudah ada tidak
--              ditimpa maupun diduplikasi. Cocok untuk MySQL 5.7+ /
--              MariaDB.
-- ============================================================

-- ------------------------------------------------------------
-- Guard: hanya jalankan bila tabel site_settings ada.
-- ------------------------------------------------------------
SET @t := (
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'site_settings'
);

SET @s := IF(
    @t = 0,
    'SELECT ''site_settings: table missing, skip'' AS note',
    'INSERT IGNORE INTO site_settings (setting_key, setting_value, setting_type, description) VALUES
        (''tool_upscale_enabled'',   ''1'',   ''bool'', ''Enable AI HD Upscale tool''),
        (''tool_erase_enabled'',     ''1'',   ''bool'', ''Enable Magic Eraser tool''),
        (''tool_faceblur_enabled'',  ''1'',   ''bool'', ''Enable Face Blur tool''),
        (''tool_palette_enabled'',   ''1'',   ''bool'', ''Enable Color Palette tool''),
        (''tool_ocr_enabled'',       ''1'',   ''bool'', ''Enable OCR tool''),
        (''tool_rembg_enabled'',     ''1'',   ''bool'', ''Enable Remove Background tool''),
        (''upscale_limit_free'',     ''3'',   ''int'',  ''AI HD Upscale daily limit for free users''),
        (''upscale_limit_premium'',  ''30'',  ''int'',  ''AI HD Upscale daily limit for premium users''),
        (''erase_limit_free'',       ''3'',   ''int'',  ''Magic Eraser daily limit for free users''),
        (''erase_limit_premium'',    ''30'',  ''int'',  ''Magic Eraser daily limit for premium users''),
        (''faceblur_limit_free'',    ''10'',  ''int'',  ''Face Blur daily limit for free users''),
        (''faceblur_limit_premium'', ''100'', ''int'',  ''Face Blur daily limit for premium users''),
        (''palette_limit_guest'',    ''20'',  ''int'',  ''Color Palette daily limit per guest IP'')'
);

PREPARE stmt FROM @s;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
