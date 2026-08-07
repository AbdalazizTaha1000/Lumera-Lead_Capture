-- ---------------------------------------------------------------------------
-- Step media + site branding
--
-- 1. `funnel_steps.image_path` — an optional image per step.
-- 2. `app_settings.site_tagline` — used for the public <title> and
--    <meta name="description">.
--
-- Idempotent: the column is only added when missing, and the setting uses
-- INSERT … ON DUPLICATE KEY so an existing value is never overwritten.
-- No lead table is touched.
--
-- Apply with:  php bin/console.php migrate
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- ------------------------------------------------- funnel_steps.image_path --
SET @needsImage := (
    SELECT COUNT(*) = 0 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'funnel_steps'
      AND COLUMN_NAME = 'image_path'
);

SET @sql := IF(
    @needsImage,
    'ALTER TABLE `funnel_steps` ADD COLUMN `image_path` VARCHAR(255) NULL DEFAULT NULL AFTER `placeholder_ar`',
    'DO 0'
);

PREPARE step_media FROM @sql;
EXECUTE step_media;
DEALLOCATE PREPARE step_media;

-- ------------------------------------------------ app_settings.site_tagline --
-- Public: it is rendered into the page metadata, so it carries no secrets.
INSERT INTO `app_settings` (`setting_key`, `setting_value`, `value_type`, `is_public`)
VALUES ('site_tagline', '', 'string', 1)
ON DUPLICATE KEY UPDATE `is_public` = 1, `value_type` = 'string';
