-- ---------------------------------------------------------------------------
-- Phase 2 — Multi funnels & company branding
--
-- Adds per-funnel branding and per-funnel delivery settings to `funnels`.
-- No existing table is altered beyond this one, and no lead data is touched.
--
-- The migration is idempotent: it inspects information_schema and only emits
-- the columns that are actually missing, so it is safe on a fresh install
-- (where schema.sql already created them) and on a Phase 1 database alike.
--
-- Apply with:  php bin/console.php migrate
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
SET SESSION group_concat_max_len = 16384;

SET @missing := (
    SELECT GROUP_CONCAT(w.ddl SEPARATOR ', ')
    FROM (
        SELECT 'company_name'      AS col, 'ADD COLUMN `company_name` VARCHAR(190) NOT NULL DEFAULT "" AFTER `name`' AS ddl
        UNION ALL SELECT 'favicon_path',     'ADD COLUMN `favicon_path` VARCHAR(255) NULL DEFAULT NULL AFTER `logo_path`'
        UNION ALL SELECT 'recipient_email',  'ADD COLUMN `recipient_email` VARCHAR(500) NULL DEFAULT NULL'
        UNION ALL SELECT 'success_button_en','ADD COLUMN `success_button_en` VARCHAR(120) NOT NULL DEFAULT ""'
        UNION ALL SELECT 'success_button_ar','ADD COLUMN `success_button_ar` VARCHAR(120) NOT NULL DEFAULT ""'
        UNION ALL SELECT 'redirect_url',     'ADD COLUMN `redirect_url` VARCHAR(500) NULL DEFAULT NULL'
        UNION ALL SELECT 'redirect_delay',   'ADD COLUMN `redirect_delay` SMALLINT UNSIGNED NOT NULL DEFAULT 5'
        UNION ALL SELECT 'webhook_url',      'ADD COLUMN `webhook_url` VARCHAR(500) NULL DEFAULT NULL'
        UNION ALL SELECT 'webhook_enabled',  'ADD COLUMN `webhook_enabled` TINYINT(1) NOT NULL DEFAULT 0'
        UNION ALL SELECT 'archived_at',      'ADD COLUMN `archived_at` DATETIME NULL DEFAULT NULL'
    ) w
    WHERE w.col NOT IN (
        SELECT COLUMN_NAME FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funnels'
    )
);

SET @sql := IF(@missing IS NULL, 'DO 0', CONCAT('ALTER TABLE `funnels` ', @missing));
PREPARE phase2_columns FROM @sql;
EXECUTE phase2_columns;
DEALLOCATE PREPARE phase2_columns;

-- Index used by the public router and the "active funnels" listing.
SET @needsIndex := (
    SELECT COUNT(*) = 0 FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'funnels' AND INDEX_NAME = 'idx_funnels_archived'
);

SET @sql := IF(@needsIndex, 'ALTER TABLE `funnels` ADD KEY `idx_funnels_archived` (`archived_at`, `status`)', 'DO 0');
PREPARE phase2_index FROM @sql;
EXECUTE phase2_index;
DEALLOCATE PREPARE phase2_index;

-- Backfill: existing funnels inherit the global company name so nothing looks
-- unbranded after the upgrade.
UPDATE `funnels` f
SET f.`company_name` = COALESCE(
        NULLIF((SELECT s.`setting_value` FROM `app_settings` s WHERE s.`setting_key` = 'company_name'), ''),
        f.`name`
    )
WHERE f.`company_name` = '' OR f.`company_name` IS NULL;

UPDATE `funnels` SET `success_button_en` = 'Done' WHERE `success_button_en` = '';
UPDATE `funnels` SET `success_button_ar` = 'تم' WHERE `success_button_ar` = '';
