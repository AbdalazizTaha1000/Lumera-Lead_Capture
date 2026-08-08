-- ---------------------------------------------------------------------------
-- Analytics engine
--
-- Three tables, deliberately separate from `leads`:
--
--   analytics_sessions       one row per visit. Carries every dimension
--                            (source, device, geo, timings) so summaries never
--                            need the event table. Retained long-term.
--   analytics_events         the timeline within a visit. Prunable.
--   analytics_daily_rollups  pre-aggregated per funnel per day. Permanent, so
--                            history survives event pruning.
--
-- No lead column is added and no lead row is touched. `lead_id` is a nullable
-- pointer with ON DELETE SET NULL, so deleting a lead can never cascade.
--
-- Idempotent: every object is created only when missing.
-- Apply with:  php bin/console.php migrate
-- ---------------------------------------------------------------------------

SET NAMES utf8mb4;

-- ------------------------------------------------------------- sessions ---
CREATE TABLE IF NOT EXISTS `analytics_sessions` (
    `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_uuid`      CHAR(36) NOT NULL,
    `funnel_id`         INT UNSIGNED NULL DEFAULT NULL,
    `published_version` INT UNSIGNED NOT NULL DEFAULT 0,

    -- HMAC(ip + user agent, ANALYTICS_HASH_SALT). One-way; no raw IP is kept.
    `visitor_hash`      CHAR(64) NOT NULL,
    `is_bot`            TINYINT(1) NOT NULL DEFAULT 0,

    `started_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at`      DATETIME NULL DEFAULT NULL,
    `abandoned_at`      DATETIME NULL DEFAULT NULL,
    `duration_seconds`  INT UNSIGNED NULL DEFAULT NULL,

    `landing_path`      VARCHAR(500) NULL DEFAULT NULL,
    `referrer`          VARCHAR(500) NULL DEFAULT NULL,
    `referrer_domain`   VARCHAR(190) NULL DEFAULT NULL,
    -- direct | organic | social | paid | referral | other
    `source_group`      VARCHAR(20) NOT NULL DEFAULT 'direct',

    `utm_source`        VARCHAR(190) NULL DEFAULT NULL,
    `utm_medium`        VARCHAR(190) NULL DEFAULT NULL,
    `utm_campaign`      VARCHAR(190) NULL DEFAULT NULL,
    `utm_content`       VARCHAR(190) NULL DEFAULT NULL,
    `utm_term`          VARCHAR(190) NULL DEFAULT NULL,

    `device_type`       VARCHAR(20) NOT NULL DEFAULT 'unknown',
    `browser`           VARCHAR(40) NOT NULL DEFAULT 'Other',
    `browser_version`   VARCHAR(20) NULL DEFAULT NULL,
    `os`                VARCHAR(40) NOT NULL DEFAULT 'Other',
    `os_version`        VARCHAR(20) NULL DEFAULT NULL,

    `country_code`      CHAR(2) NULL DEFAULT NULL,
    `country_name`      VARCHAR(80) NULL DEFAULT NULL,
    `city`              VARCHAR(120) NULL DEFAULT NULL,

    `language`          VARCHAR(12) NULL DEFAULT NULL,
    `timezone`          VARCHAR(64) NULL DEFAULT NULL,

    `first_step_key`    VARCHAR(64) NULL DEFAULT NULL,
    `last_step_key`     VARCHAR(64) NULL DEFAULT NULL,
    `steps_started`     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `steps_completed`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,

    `lead_id`           BIGINT UNSIGNED NULL DEFAULT NULL,

    `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_sessions_uuid` (`session_uuid`),
    KEY `idx_sessions_funnel_started` (`funnel_id`, `started_at`),
    KEY `idx_sessions_visitor` (`visitor_hash`),
    KEY `idx_sessions_started` (`started_at`),
    KEY `idx_sessions_completed` (`completed_at`),
    KEY `idx_sessions_utm_source` (`utm_source`),
    KEY `idx_sessions_utm_campaign` (`utm_campaign`),
    KEY `idx_sessions_country` (`country_code`),
    KEY `idx_sessions_open` (`is_bot`, `completed_at`, `abandoned_at`, `last_seen_at`),
    KEY `idx_sessions_lead` (`lead_id`),
    CONSTRAINT `fk_sessions_funnel` FOREIGN KEY (`funnel_id`)
        REFERENCES `funnels` (`id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_sessions_lead` FOREIGN KEY (`lead_id`)
        REFERENCES `leads` (`id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- events ---
CREATE TABLE IF NOT EXISTS `analytics_events` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `session_id`    BIGINT UNSIGNED NOT NULL,
    `funnel_id`     INT UNSIGNED NULL DEFAULT NULL,
    `event_type`    VARCHAR(24) NOT NULL,
    `step_key`      VARCHAR(64) NULL DEFAULT NULL,
    `step_id`       INT UNSIGNED NULL DEFAULT NULL,
    `step_position` SMALLINT UNSIGNED NULL DEFAULT NULL,
    `event_value`   VARCHAR(190) NULL DEFAULT NULL,
    -- Allow-listed keys only. Visitor answers are never written here.
    `metadata_json` JSON NULL DEFAULT NULL,
    `occurred_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    KEY `idx_events_session` (`session_id`, `occurred_at`),
    KEY `idx_events_funnel_type` (`funnel_id`, `event_type`, `occurred_at`),
    KEY `idx_events_step` (`funnel_id`, `step_key`, `event_type`),
    KEY `idx_events_occurred` (`occurred_at`),
    CONSTRAINT `fk_events_session` FOREIGN KEY (`session_id`)
        REFERENCES `analytics_sessions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------------- rollups ---
CREATE TABLE IF NOT EXISTS `analytics_daily_rollups` (
    `id`                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `funnel_id`             INT UNSIGNED NOT NULL,
    `date`                  DATE NOT NULL,

    `views`                 INT UNSIGNED NOT NULL DEFAULT 0,
    `sessions`              INT UNSIGNED NOT NULL DEFAULT 0,
    `unique_visitors`       INT UNSIGNED NOT NULL DEFAULT 0,
    `engaged_sessions`      INT UNSIGNED NOT NULL DEFAULT 0,
    `completions`           INT UNSIGNED NOT NULL DEFAULT 0,
    `leads`                 INT UNSIGNED NOT NULL DEFAULT 0,
    `abandonments`          INT UNSIGNED NOT NULL DEFAULT 0,

    `completion_rate`       DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `avg_completion_seconds` INT UNSIGNED NULL DEFAULT NULL,

    `desktop_sessions`      INT UNSIGNED NOT NULL DEFAULT 0,
    `mobile_sessions`       INT UNSIGNED NOT NULL DEFAULT 0,
    `tablet_sessions`       INT UNSIGNED NOT NULL DEFAULT 0,

    -- Set when the day predates the analytics engine: lead counts are real,
    -- but visits and rates were never measured and must not be invented.
    `lead_only`             TINYINT(1) NOT NULL DEFAULT 0,

    `created_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rollup_funnel_date` (`funnel_id`, `date`),
    KEY `idx_rollup_date` (`date`),
    CONSTRAINT `fk_rollup_funnel` FOREIGN KEY (`funnel_id`)
        REFERENCES `funnels` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
