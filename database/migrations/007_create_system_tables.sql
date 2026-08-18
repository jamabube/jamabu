-- ===========================================================================
-- Migration 007 : Runtime settings, replay protection and rate limiting
-- ===========================================================================

-- @UP

-- ---------------------------------------------------------------------------
-- system_settings
--
-- Runtime configuration an administrator may change without touching a file.
-- Values are typed so the settings service can cast them back correctly, and
-- flagged when they must never appear in a form or an export.
-- ---------------------------------------------------------------------------
CREATE TABLE `system_settings` (
    `setting_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `setting_key`  VARCHAR(80)  NOT NULL COMMENT 'Dot notation matching the configuration file it overrides',
    `setting_group` VARCHAR(40) NOT NULL DEFAULT 'general',
    `label`        VARCHAR(120) NOT NULL,
    `description`  VARCHAR(255)     NULL,
    `value`        TEXT             NULL,
    `default_value` TEXT            NULL,
    `value_type`   ENUM('string','integer','boolean','float','json','text','password','select') NOT NULL DEFAULT 'string',
    `options`      JSON             NULL COMMENT 'Permitted values for a select-type setting',
    `validation`   VARCHAR(120)     NULL COMMENT 'Validation rule string applied when the setting is saved',
    `is_sensitive` TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'Masked in the interface and excluded from exports',
    `is_editable`  TINYINT(1)   NOT NULL DEFAULT 1,
    `requires_restart` TINYINT(1) NOT NULL DEFAULT 0,
    `sort_order`   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `updated_by`   INT UNSIGNED     NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_id`),
    UNIQUE KEY `uq_system_settings_key` (`setting_key`),
    KEY `idx_system_settings_group` (`setting_group`, `sort_order`),
    CONSTRAINT `fk_system_settings_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Administrator-editable runtime configuration';

-- ---------------------------------------------------------------------------
-- api_nonces
--
-- Replay protection. A device sends a nonce with every request; the unique
-- index makes a second use of the same nonce impossible even under concurrent
-- requests, because the database rejects the duplicate insert rather than the
-- application losing a race between "check" and "record".
-- ---------------------------------------------------------------------------
CREATE TABLE `api_nonces` (
    `nonce_id`    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`   INT UNSIGNED     NULL,
    `identity`    VARCHAR(60)  NOT NULL COMMENT 'Device code, or the IP for an unauthenticated caller',
    `nonce`       VARCHAR(64)  NOT NULL,
    `request_timestamp` DATETIME NOT NULL COMMENT 'Timestamp the client claimed',
    `expires_at`  DATETIME     NOT NULL COMMENT 'When the nonce may be forgotten',
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`nonce_id`),
    UNIQUE KEY `uq_api_nonces_identity_nonce` (`identity`, `nonce`),
    KEY `idx_api_nonces_expiry` (`expires_at`),
    KEY `idx_api_nonces_device` (`device_id`, `created_at`),
    CONSTRAINT `fk_api_nonces_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Consumed request nonces, preventing replay of a captured request';

-- ---------------------------------------------------------------------------
-- rate_limit_counters
--
-- Fixed-window counters keyed by bucket and identity. Kept in the database
-- rather than in memory so the limit still holds across several PHP workers,
-- which is exactly the case a memory-local counter fails to cover.
-- ---------------------------------------------------------------------------
CREATE TABLE `rate_limit_counters` (
    `counter_id`   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `bucket`       VARCHAR(40)  NOT NULL COMMENT 'login, device-auth, access-scan, ...',
    `identity`     VARCHAR(100) NOT NULL COMMENT 'IP address, device code or username',
    `window_start` DATETIME     NOT NULL,
    `window_seconds` SMALLINT UNSIGNED NOT NULL,
    `hits`         INT UNSIGNED NOT NULL DEFAULT 1,
    `blocked_until` DATETIME        NULL,
    `last_hit_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`counter_id`),
    UNIQUE KEY `uq_rate_limit_window` (`bucket`, `identity`, `window_start`),
    KEY `idx_rate_limit_identity` (`identity`, `last_hit_at`),
    KEY `idx_rate_limit_blocked` (`blocked_until`),
    KEY `idx_rate_limit_window_start` (`window_start`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Fixed-window request counters shared across workers';

-- ---------------------------------------------------------------------------
-- schema_migrations
--
-- Applied migrations. The checksum detects a migration file edited after it
-- was applied, which would otherwise leave two deployments silently divergent.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `schema_migrations` (
    `migration_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `migration`    VARCHAR(180) NOT NULL,
    `batch`        INT UNSIGNED NOT NULL DEFAULT 1,
    `checksum`     CHAR(64)     NOT NULL,
    `duration_ms`  INT UNSIGNED NOT NULL DEFAULT 0,
    `applied_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`migration_id`),
    UNIQUE KEY `uq_schema_migrations_name` (`migration`),
    KEY `idx_schema_migrations_batch` (`batch`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Applied schema migrations';

-- @DOWN
DROP TABLE IF EXISTS `rate_limit_counters`;
DROP TABLE IF EXISTS `api_nonces`;
DROP TABLE IF EXISTS `system_settings`;
