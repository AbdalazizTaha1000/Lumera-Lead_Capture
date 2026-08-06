-- ---------------------------------------------------------------------------
-- Lumera Lead Capture — full schema
-- MySQL 8.0+ / MariaDB 10.5+
-- Charset utf8mb4 (full Arabic + emoji support)
-- Apply with:  php bin/console.php install
--        or:  mysql -u user -p dbname < database/schema.sql
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- admin_users
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `admin_users` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`          VARCHAR(190) NOT NULL,
    `password_hash`  VARCHAR(255) NOT NULL,
    `name`           VARCHAR(120) NOT NULL DEFAULT 'Administrator',
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `last_login_at`  DATETIME NULL DEFAULT NULL,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_admin_users_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- funnels
-- Explicit columns (no monolithic config blob). `enabled_languages` is a short
-- scalar list only.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `funnels` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `slug`                   VARCHAR(120) NOT NULL,
    `name`                   VARCHAR(190) NOT NULL,
    `company_name`           VARCHAR(190) NOT NULL DEFAULT '',        -- per-funnel branding
    `status`                 VARCHAR(20) NOT NULL DEFAULT 'active',   -- active | paused | draft
    `default_language`       VARCHAR(5) NOT NULL DEFAULT 'en',
    `enabled_languages`      VARCHAR(64) NOT NULL DEFAULT 'en,ar',    -- comma separated

    `logo_path`              VARCHAR(255) NULL DEFAULT NULL,
    `favicon_path`           VARCHAR(255) NULL DEFAULT NULL,
    `background_image_path`  VARCHAR(255) NULL DEFAULT NULL,
    `primary_color`          VARCHAR(9) NOT NULL DEFAULT '#0F2E4C',
    `accent_color`           VARCHAR(9) NOT NULL DEFAULT '#C9A227',   -- the "secondary" brand colour
    `background_color`       VARCHAR(9) NOT NULL DEFAULT '#F7F8FA',

    `submit_label_en`        VARCHAR(120) NOT NULL DEFAULT 'Submit',
    `submit_label_ar`        VARCHAR(120) NOT NULL DEFAULT 'إرسال',
    `success_title_en`       VARCHAR(190) NOT NULL DEFAULT 'Thank you',
    `success_title_ar`       VARCHAR(190) NOT NULL DEFAULT 'شكراً لك',
    `success_message_en`     TEXT NULL,
    `success_message_ar`     TEXT NULL,
    `success_button_en`      VARCHAR(120) NOT NULL DEFAULT 'Done',
    `success_button_ar`      VARCHAR(120) NOT NULL DEFAULT 'تم',

    -- Per-funnel delivery settings. Secrets stay in .env; these are per-funnel
    -- destinations, not credentials.
    `recipient_email`        VARCHAR(500) NULL DEFAULT NULL,          -- overrides LEAD_RECIPIENT_EMAIL
    `redirect_url`           VARCHAR(500) NULL DEFAULT NULL,
    `redirect_delay`         SMALLINT UNSIGNED NOT NULL DEFAULT 5,
    `webhook_url`            VARCHAR(500) NULL DEFAULT NULL,
    `webhook_enabled`        TINYINT(1) NOT NULL DEFAULT 0,

    `privacy_policy_url`     VARCHAR(255) NULL DEFAULT NULL,
    `whatsapp_enabled`       TINYINT(1) NOT NULL DEFAULT 1,
    `whatsapp_label_en`      VARCHAR(120) NOT NULL DEFAULT 'Chat on WhatsApp',
    `whatsapp_label_ar`      VARCHAR(120) NOT NULL DEFAULT 'تواصل عبر واتساب',

    `progress_bar_enabled`   TINYINT(1) NOT NULL DEFAULT 1,
    `step_counter_enabled`   TINYINT(1) NOT NULL DEFAULT 1,
    `back_button_enabled`    TINYINT(1) NOT NULL DEFAULT 1,
    `save_progress_enabled`  TINYINT(1) NOT NULL DEFAULT 1,
    `min_completion_seconds` SMALLINT UNSIGNED NOT NULL DEFAULT 5,

    `published_version`      INT UNSIGNED NOT NULL DEFAULT 0,
    `published_at`           DATETIME NULL DEFAULT NULL,
    `draft_updated_at`       DATETIME NULL DEFAULT NULL,

    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `archived_at`            DATETIME NULL DEFAULT NULL,              -- restorable; hidden from public
    `deleted_at`             DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_funnels_slug` (`slug`),
    KEY `idx_funnels_status` (`status`),
    KEY `idx_funnels_archived` (`archived_at`, `status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- funnel_steps  (draft / working configuration)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `funnel_steps` (
    `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `funnel_id`              INT UNSIGNED NOT NULL,
    `step_key`               VARCHAR(64) NOT NULL,
    `step_type`              VARCHAR(32) NOT NULL,

    `title_en`               VARCHAR(255) NOT NULL DEFAULT '',
    `title_ar`               VARCHAR(255) NOT NULL DEFAULT '',
    `description_en`         TEXT NULL,
    `description_ar`         TEXT NULL,
    `placeholder_en`         VARCHAR(190) NULL DEFAULT NULL,
    `placeholder_ar`         VARCHAR(190) NULL DEFAULT NULL,

    `is_required`            TINYINT(1) NOT NULL DEFAULT 1,
    `is_active`              TINYINT(1) NOT NULL DEFAULT 1,
    `auto_advance`           TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order`             SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    `min_length`             SMALLINT UNSIGNED NULL DEFAULT NULL,
    `max_length`             SMALLINT UNSIGNED NULL DEFAULT NULL,
    `min_value`              DECIMAL(18,4) NULL DEFAULT NULL,
    `max_value`              DECIMAL(18,4) NULL DEFAULT NULL,
    `validation_pattern`     VARCHAR(255) NULL DEFAULT NULL,
    `validation_message_en`  VARCHAR(255) NULL DEFAULT NULL,
    `validation_message_ar`  VARCHAR(255) NULL DEFAULT NULL,

    -- conditional logic preparation (unused by the seeded funnel)
    `condition_parent_key`   VARCHAR(64) NULL DEFAULT NULL,
    `condition_operator`     VARCHAR(20) NULL DEFAULT NULL,   -- equals | not_equals | contains
    `condition_value`        VARCHAR(190) NULL DEFAULT NULL,

    `created_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_steps_funnel_key` (`funnel_id`, `step_key`),
    KEY `idx_steps_order` (`funnel_id`, `is_active`, `sort_order`),
    CONSTRAINT `fk_steps_funnel` FOREIGN KEY (`funnel_id`)
        REFERENCES `funnels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- funnel_step_options
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `funnel_step_options` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `step_id`       INT UNSIGNED NOT NULL,
    `option_value`  VARCHAR(64) NOT NULL,
    `label_en`      VARCHAR(190) NOT NULL DEFAULT '',
    `label_ar`      VARCHAR(190) NOT NULL DEFAULT '',
    `icon`          VARCHAR(64) NULL DEFAULT NULL,
    `score`         SMALLINT NOT NULL DEFAULT 0,
    `sort_order`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
    `metadata`      JSON NULL DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_options_step_value` (`step_id`, `option_value`),
    KEY `idx_options_order` (`step_id`, `is_active`, `sort_order`),
    CONSTRAINT `fk_options_step` FOREIGN KEY (`step_id`)
        REFERENCES `funnel_steps` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- funnel_contact_fields
-- System keys are never deleted, only deactivated (protects historic leads).
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `funnel_contact_fields` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `funnel_id`      INT UNSIGNED NOT NULL,
    `field_key`      VARCHAR(48) NOT NULL,
    `field_type`     VARCHAR(32) NOT NULL DEFAULT 'text',  -- text|email|tel|select|country_code
    `label_en`       VARCHAR(190) NOT NULL DEFAULT '',
    `label_ar`       VARCHAR(190) NOT NULL DEFAULT '',
    `placeholder_en` VARCHAR(190) NULL DEFAULT NULL,
    `placeholder_ar` VARCHAR(190) NULL DEFAULT NULL,
    `is_required`    TINYINT(1) NOT NULL DEFAULT 0,
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `is_system`      TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `min_length`     SMALLINT UNSIGNED NULL DEFAULT NULL,
    `max_length`     SMALLINT UNSIGNED NULL DEFAULT NULL,
    `validation_pattern` VARCHAR(255) NULL DEFAULT NULL,
    `choices`        JSON NULL DEFAULT NULL,  -- [{value,label_en,label_ar}] for select fields
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_contact_fields_funnel_key` (`funnel_id`, `field_key`),
    KEY `idx_contact_fields_order` (`funnel_id`, `is_active`, `sort_order`),
    CONSTRAINT `fk_contact_fields_funnel` FOREIGN KEY (`funnel_id`)
        REFERENCES `funnels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- funnel_versions — immutable published snapshots.
-- The public funnel reads ONLY from here, so draft edits are never exposed and
-- a save/reorder in progress can never be partially rendered.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `funnel_versions` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `funnel_id`     INT UNSIGNED NOT NULL,
    `version`       INT UNSIGNED NOT NULL,
    `snapshot_json` LONGTEXT NOT NULL,
    `published_by`  INT UNSIGNED NULL DEFAULT NULL,
    `notes`         VARCHAR(255) NULL DEFAULT NULL,
    `published_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_versions_funnel_version` (`funnel_id`, `version`),
    KEY `idx_versions_funnel` (`funnel_id`, `published_at`),
    CONSTRAINT `fk_versions_funnel` FOREIGN KEY (`funnel_id`)
        REFERENCES `funnels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_versions_admin` FOREIGN KEY (`published_by`)
        REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- leads
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `leads` (
    `id`                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `funnel_id`           INT UNSIGNED NULL DEFAULT NULL,
    `funnel_version`      INT UNSIGNED NOT NULL DEFAULT 0,

    `full_name`           VARCHAR(190) NOT NULL DEFAULT '',
    `country_code`        VARCHAR(8) NULL DEFAULT NULL,
    `phone`               VARCHAR(40) NULL DEFAULT NULL,
    `phone_normalized`    VARCHAR(40) NULL DEFAULT NULL,
    `email`               VARCHAR(190) NULL DEFAULT NULL,
    `preferred_language`  VARCHAR(20) NULL DEFAULT NULL,
    `interface_language`  VARCHAR(5) NOT NULL DEFAULT 'en',

    `consent_given`       TINYINT(1) NOT NULL DEFAULT 0,
    `consent_at`          DATETIME NULL DEFAULT NULL,

    `lead_score`          INT NOT NULL DEFAULT 0,
    `status`              VARCHAR(20) NOT NULL DEFAULT 'new',

    `utm_source`          VARCHAR(190) NULL DEFAULT NULL,
    `utm_medium`          VARCHAR(190) NULL DEFAULT NULL,
    `utm_campaign`        VARCHAR(190) NULL DEFAULT NULL,
    `utm_content`         VARCHAR(190) NULL DEFAULT NULL,
    `utm_term`            VARCHAR(190) NULL DEFAULT NULL,
    `gclid`               VARCHAR(255) NULL DEFAULT NULL,
    `fbclid`              VARCHAR(255) NULL DEFAULT NULL,
    `referrer`            VARCHAR(500) NULL DEFAULT NULL,
    `landing_page`        VARCHAR(500) NULL DEFAULT NULL,

    `device_type`         VARCHAR(20) NULL DEFAULT NULL,
    `user_agent`          VARCHAR(500) NULL DEFAULT NULL,
    `screen_size`         VARCHAR(20) NULL DEFAULT NULL,
    `ip_hash`             CHAR(64) NULL DEFAULT NULL,
    `ip_address`          VARCHAR(45) NULL DEFAULT NULL,   -- only when STORE_RAW_IP=true

    `submission_hash`     CHAR(64) NULL DEFAULT NULL,      -- duplicate detection
    `email_status`        VARCHAR(20) NOT NULL DEFAULT 'pending', -- pending|sent|failed|skipped
    `email_error`         VARCHAR(500) NULL DEFAULT NULL,
    `email_sent_at`       DATETIME NULL DEFAULT NULL,

    `submitted_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`          DATETIME NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_leads_funnel` (`funnel_id`),
    KEY `idx_leads_status` (`status`),
    KEY `idx_leads_submitted` (`submitted_at`),
    KEY `idx_leads_source` (`utm_source`),
    KEY `idx_leads_campaign` (`utm_campaign`),
    KEY `idx_leads_phone` (`phone_normalized`),
    KEY `idx_leads_dup` (`submission_hash`, `submitted_at`),
    KEY `idx_leads_deleted` (`deleted_at`),
    CONSTRAINT `fk_leads_funnel` FOREIGN KEY (`funnel_id`)
        REFERENCES `funnels` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- lead_answers — one row per answered step, with label snapshots so a lead
-- stays readable after the funnel is edited.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lead_answers` (
    `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lead_id`        BIGINT UNSIGNED NOT NULL,
    `step_id`        INT UNSIGNED NULL DEFAULT NULL,
    `step_key`       VARCHAR(64) NOT NULL,
    `step_title`     VARCHAR(255) NOT NULL DEFAULT '',
    `step_type`      VARCHAR(32) NOT NULL,
    `answer_value`   TEXT NULL,
    `answer_label`   TEXT NULL,
    `answer_json`    JSON NULL DEFAULT NULL,
    `score`          INT NOT NULL DEFAULT 0,
    `sort_order`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_answers_lead` (`lead_id`, `sort_order`),
    KEY `idx_answers_step_key` (`step_key`),
    CONSTRAINT `fk_answers_lead` FOREIGN KEY (`lead_id`)
        REFERENCES `leads` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_answers_step` FOREIGN KEY (`step_id`)
        REFERENCES `funnel_steps` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- lead_notes
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `lead_notes` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `lead_id`       BIGINT UNSIGNED NOT NULL,
    `admin_user_id` INT UNSIGNED NULL DEFAULT NULL,
    `note`          TEXT NOT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_notes_lead` (`lead_id`, `created_at`),
    CONSTRAINT `fk_notes_lead` FOREIGN KEY (`lead_id`)
        REFERENCES `leads` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_notes_admin` FOREIGN KEY (`admin_user_id`)
        REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- app_settings — safe, non-secret application settings only.
-- SMTP / DB / APP_SECRET never live here.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `app_settings` (
    `setting_key`   VARCHAR(64) NOT NULL,
    `setting_value` TEXT NULL,
    `value_type`    VARCHAR(16) NOT NULL DEFAULT 'string', -- string|bool|int|json
    `is_public`     TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- login_attempts
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `login_attempts` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `email`        VARCHAR(190) NOT NULL DEFAULT '',
    `ip_hash`      CHAR(64) NOT NULL DEFAULT '',
    `successful`   TINYINT(1) NOT NULL DEFAULT 0,
    `attempted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_login_attempts_lookup` (`ip_hash`, `attempted_at`),
    KEY `idx_login_attempts_email` (`email`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- rate_limit_entries — generic fixed-window counters
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `rate_limit_entries` (
    `bucket_key`   CHAR(64) NOT NULL,
    `hits`         INT UNSIGNED NOT NULL DEFAULT 0,
    `window_start` DATETIME NOT NULL,
    `expires_at`   DATETIME NOT NULL,
    PRIMARY KEY (`bucket_key`),
    KEY `idx_rate_limit_expiry` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- audit_logs
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `admin_user_id` INT UNSIGNED NULL DEFAULT NULL,
    `action`        VARCHAR(64) NOT NULL,
    `entity_type`   VARCHAR(64) NULL DEFAULT NULL,
    `entity_id`     VARCHAR(64) NULL DEFAULT NULL,
    `metadata`      JSON NULL DEFAULT NULL,
    `ip_hash`       CHAR(64) NULL DEFAULT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_audit_admin` (`admin_user_id`, `created_at`),
    KEY `idx_audit_action` (`action`, `created_at`),
    KEY `idx_audit_entity` (`entity_type`, `entity_id`),
    CONSTRAINT `fk_audit_admin` FOREIGN KEY (`admin_user_id`)
        REFERENCES `admin_users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
