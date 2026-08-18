-- ===========================================================================
-- Migration 001 : Reference and master data tables
--
-- These tables hold the slowly-changing vocabulary the rest of the schema
-- refers to. Keeping them as tables rather than hardcoded enumerations means
-- an administrator can extend the vocabulary (a new vehicle type, a new
-- department) without a code change or a schema migration.
-- ===========================================================================

-- @UP

-- ---------------------------------------------------------------------------
-- reference_codes
--
-- A single, categorised lookup for the small closed vocabularies the
-- specification names as master tables: access types, status codes,
-- verification methods and severities. One table with a category column keeps
-- the schema from sprawling into a dozen two-column tables while still giving
-- every value a row that reports and drop-downs can join against.
-- ---------------------------------------------------------------------------
CREATE TABLE `reference_codes` (
    `reference_code_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `category`          VARCHAR(50)  NOT NULL COMMENT 'access_type, access_result, verification_method, severity, ...',
    `code`              VARCHAR(50)  NOT NULL COMMENT 'Stable machine value used by application logic',
    `label`             VARCHAR(100) NOT NULL COMMENT 'Human-readable text shown in the interface',
    `description`       VARCHAR(255)     NULL,
    `badge_class`       VARCHAR(30)      NULL COMMENT 'Bootstrap contextual class used when rendering as a badge',
    `sort_order`        SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `is_system`         TINYINT(1)   NOT NULL DEFAULT 0 COMMENT 'System values may not be deleted by an administrator',
    `status`            ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`reference_code_id`),
    UNIQUE KEY `uq_reference_codes_category_code` (`category`, `code`),
    KEY `idx_reference_codes_category_status` (`category`, `status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Categorised lookup values for closed vocabularies';

-- ---------------------------------------------------------------------------
-- system_modules
--
-- The catalogue of functional modules. Permissions are grouped by module, the
-- error log records the module a failure occurred in, and the audit trail
-- records the module an action belongs to; all three join here.
-- ---------------------------------------------------------------------------
CREATE TABLE `system_modules` (
    `module_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `module_key`  VARCHAR(50)  NOT NULL COMMENT 'Lower-case identifier, e.g. "vehicles"',
    `module_name` VARCHAR(100) NOT NULL,
    `description` VARCHAR(255)     NULL,
    `icon`        VARCHAR(50)      NULL COMMENT 'Font Awesome icon class',
    `sort_order`  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`module_id`),
    UNIQUE KEY `uq_system_modules_key` (`module_key`),
    KEY `idx_system_modules_status` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Catalogue of functional modules used for grouping and reporting';

-- ---------------------------------------------------------------------------
-- departments
-- ---------------------------------------------------------------------------
CREATE TABLE `departments` (
    `department_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `department_code` VARCHAR(20)  NOT NULL,
    `department_name` VARCHAR(120) NOT NULL,
    `description`     VARCHAR(255)     NULL,
    `contact_number`  VARCHAR(30)      NULL,
    `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME         NULL,
    PRIMARY KEY (`department_id`),
    UNIQUE KEY `uq_departments_code` (`department_code`),
    KEY `idx_departments_status` (`status`),
    KEY `idx_departments_name` (`department_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Organisational units that personnel belong to';

-- ---------------------------------------------------------------------------
-- vehicle_types
-- ---------------------------------------------------------------------------
CREATE TABLE `vehicle_types` (
    `vehicle_type_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type_code`       VARCHAR(20)  NOT NULL,
    `type_name`       VARCHAR(60)  NOT NULL,
    `description`     VARCHAR(255)     NULL,
    `icon`            VARCHAR(50)      NULL,
    `sort_order`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`vehicle_type_id`),
    UNIQUE KEY `uq_vehicle_types_code` (`type_code`),
    KEY `idx_vehicle_types_status` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Classification of registered vehicles';

-- ---------------------------------------------------------------------------
-- visitor_types
-- ---------------------------------------------------------------------------
CREATE TABLE `visitor_types` (
    `visitor_type_id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type_code`             VARCHAR(20)  NOT NULL,
    `type_name`             VARCHAR(60)  NOT NULL,
    `description`           VARCHAR(255)     NULL,
    `default_validity_hours` SMALLINT UNSIGNED NOT NULL DEFAULT 12
        COMMENT 'Default lifetime of a pass issued to this visitor category',
    `requires_authoriser`   TINYINT(1)   NOT NULL DEFAULT 1,
    `sort_order`            SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `status`                ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`visitor_type_id`),
    UNIQUE KEY `uq_visitor_types_code` (`type_code`),
    KEY `idx_visitor_types_status` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Categories of temporary visitor, each with its own default validity';

-- ---------------------------------------------------------------------------
-- notification_types
--
-- Mirrors config/notifications.php so an administrator can retune priority and
-- audience at runtime. The configuration file supplies the bootstrap defaults;
-- this table wins once a row exists.
-- ---------------------------------------------------------------------------
CREATE TABLE `notification_types` (
    `notification_type_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type_key`             VARCHAR(60)  NOT NULL COMMENT 'e.g. vehicle.entered, device.offline',
    `type_name`            VARCHAR(120) NOT NULL,
    `description`          VARCHAR(255)     NULL,
    `default_priority`     ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal',
    `audience_roles`       VARCHAR(255) NOT NULL DEFAULT '' COMMENT 'Comma-separated role slugs; empty means permission-based',
    `channel_database`     TINYINT(1)   NOT NULL DEFAULT 1,
    `channel_mail`         TINYINT(1)   NOT NULL DEFAULT 0,
    `is_enabled`           TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`notification_type_id`),
    UNIQUE KEY `uq_notification_types_key` (`type_key`),
    KEY `idx_notification_types_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Runtime configuration for each kind of notification';

-- ---------------------------------------------------------------------------
-- security_rules
--
-- Thresholds for the detection logic (rate limiting, flood detection, replay
-- windows, lockout). Holding them in a table lets an administrator tighten a
-- threshold during an incident without a redeploy, and records who did.
-- ---------------------------------------------------------------------------
CREATE TABLE `security_rules` (
    `security_rule_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rule_key`         VARCHAR(60)  NOT NULL,
    `rule_name`        VARCHAR(120) NOT NULL,
    `description`      VARCHAR(255)     NULL,
    `threshold_value`  INT          NOT NULL DEFAULT 0,
    `window_seconds`   INT UNSIGNED NOT NULL DEFAULT 60,
    `action`           ENUM('log','notify','block','lock') NOT NULL DEFAULT 'log',
    `severity`         ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    `is_enabled`       TINYINT(1)   NOT NULL DEFAULT 1,
    `updated_by`       INT UNSIGNED     NULL,
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`security_rule_id`),
    UNIQUE KEY `uq_security_rules_key` (`rule_key`),
    KEY `idx_security_rules_enabled` (`is_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Administrator-tunable thresholds for the detection engine';

-- @DOWN
DROP TABLE IF EXISTS `security_rules`;
DROP TABLE IF EXISTS `notification_types`;
DROP TABLE IF EXISTS `visitor_types`;
DROP TABLE IF EXISTS `vehicle_types`;
DROP TABLE IF EXISTS `departments`;
DROP TABLE IF EXISTS `system_modules`;
DROP TABLE IF EXISTS `reference_codes`;
