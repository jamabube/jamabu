-- ===========================================================================
-- Migration 006 : Audit, error, security, API log and notification tables
--
-- Audit records are immutable by design: the table carries no updated_at, the
-- application exposes no update or delete path, and the recommended deployment
-- grants the application account only INSERT and SELECT on it (see
-- docs/deployment.md).
-- ===========================================================================

-- @UP

-- ---------------------------------------------------------------------------
-- audit_logs
-- ---------------------------------------------------------------------------
CREATE TABLE `audit_logs` (
    `audit_log_id`  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`       INT UNSIGNED     NULL COMMENT 'NULL for device-originated or anonymous actions',
    `username`      VARCHAR(50)      NULL COMMENT 'Snapshot: the record must stay readable after a user is removed',
    `role_name`     VARCHAR(80)      NULL,
    `device_id`     INT UNSIGNED     NULL,
    `module`        VARCHAR(50)  NOT NULL,
    `action`        VARCHAR(60)  NOT NULL COMMENT 'created, updated, deleted, login, export, ...',
    `description`   VARCHAR(255) NOT NULL,
    `record_type`   VARCHAR(60)      NULL COMMENT 'Table or entity affected',
    `record_id`     VARCHAR(40)      NULL,
    `old_values`    JSON             NULL COMMENT 'Only the fields that changed, with sensitive keys redacted',
    `new_values`    JSON             NULL,
    `ip_address`    VARCHAR(45)      NULL,
    `user_agent`    VARCHAR(255)     NULL,
    `browser`       VARCHAR(60)      NULL,
    `platform`      VARCHAR(60)      NULL,
    `request_id`    CHAR(36)         NULL,
    `request_method` VARCHAR(10)     NULL,
    `request_path`  VARCHAR(255)     NULL,
    `status`        ENUM('success','failure') NOT NULL DEFAULT 'success',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`audit_log_id`),
    KEY `idx_audit_logs_created` (`created_at`),
    KEY `idx_audit_logs_user_created` (`user_id`, `created_at`),
    KEY `idx_audit_logs_module_action` (`module`, `action`, `created_at`),
    KEY `idx_audit_logs_record` (`record_type`, `record_id`),
    KEY `idx_audit_logs_device` (`device_id`, `created_at`),
    KEY `idx_audit_logs_request` (`request_id`),
    CONSTRAINT `fk_audit_logs_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_audit_logs_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Immutable record of every significant action';

-- ---------------------------------------------------------------------------
-- error_logs
--
-- The original diagnostic fields are written once and never modified. An
-- administrator adds a resolution alongside them; the columns for that are
-- separate so a resolution can never overwrite evidence.
-- ---------------------------------------------------------------------------
CREATE TABLE `error_logs` (
    `error_log_id`   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `reference`      VARCHAR(16)  NOT NULL COMMENT 'Quoted to the user so a report can be traced to this row',
    `module`         VARCHAR(50)  NOT NULL,
    `controller`     VARCHAR(120)     NULL,
    `method`         VARCHAR(60)      NULL,
    `severity`       ENUM('notice','warning','error','critical','alert','emergency') NOT NULL DEFAULT 'error',
    `exception_class` VARCHAR(150)    NULL,
    `message`        TEXT         NOT NULL,
    `file`           VARCHAR(255)     NULL,
    `line`           INT UNSIGNED     NULL,
    `stack_trace`    MEDIUMTEXT       NULL,
    `context`        JSON             NULL,
    `user_id`        INT UNSIGNED     NULL,
    `device_id`      INT UNSIGNED     NULL,
    `ip_address`     VARCHAR(45)      NULL,
    `request_id`     CHAR(36)         NULL,
    `request_method` VARCHAR(10)      NULL,
    `request_path`   VARCHAR(255)     NULL,
    `occurrence_count` INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'Identical failures are folded into one row so a loop cannot flood the table',
    `first_seen_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_seen_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fingerprint`    CHAR(64)     NOT NULL COMMENT 'Hash of class+file+line, used to fold duplicates',
    `resolved`       TINYINT(1)   NOT NULL DEFAULT 0,
    `resolved_by`    INT UNSIGNED     NULL,
    `resolved_at`    DATETIME         NULL,
    `resolution_notes` TEXT           NULL,
    `assigned_to`    INT UNSIGNED     NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`error_log_id`),
    UNIQUE KEY `uq_error_logs_reference` (`reference`),
    KEY `idx_error_logs_fingerprint` (`fingerprint`, `resolved`),
    KEY `idx_error_logs_severity_time` (`severity`, `last_seen_at`),
    KEY `idx_error_logs_module` (`module`, `last_seen_at`),
    KEY `idx_error_logs_resolved` (`resolved`, `last_seen_at`),
    KEY `idx_error_logs_user` (`user_id`),
    KEY `idx_error_logs_device` (`device_id`),
    CONSTRAINT `fk_error_logs_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_error_logs_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_error_logs_resolved_by`
        FOREIGN KEY (`resolved_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_error_logs_assigned_to`
        FOREIGN KEY (`assigned_to`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Structured application errors with administrator resolution tracking';

-- ---------------------------------------------------------------------------
-- security_events
-- ---------------------------------------------------------------------------
CREATE TABLE `security_events` (
    `security_event_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `event_type`     VARCHAR(60)  NOT NULL
        COMMENT 'failed_login, unknown_rfid, unknown_device, replay_attack, flood_detected, ...',
    `severity`       ENUM('low','medium','high','critical') NOT NULL DEFAULT 'medium',
    `description`    VARCHAR(500) NOT NULL,
    `detail`         JSON             NULL COMMENT 'Structured evidence: header values, counters, thresholds',
    `user_id`        INT UNSIGNED     NULL,
    `username`       VARCHAR(50)      NULL COMMENT 'As submitted, for attempts on accounts that do not exist',
    `device_id`      INT UNSIGNED     NULL,
    `device_code`    VARCHAR(40)      NULL,
    `ip_address`     VARCHAR(45)      NULL,
    `user_agent`     VARCHAR(255)     NULL,
    `request_id`     CHAR(36)         NULL,
    `request_method` VARCHAR(10)      NULL,
    `request_path`   VARCHAR(255)     NULL,
    `action_taken`   VARCHAR(120)     NULL COMMENT 'rejected, rate_limited, account_locked, blocked',
    `status`         ENUM('new','acknowledged','investigating','resolved','false_positive') NOT NULL DEFAULT 'new',
    `acknowledged_by` INT UNSIGNED    NULL,
    `acknowledged_at` DATETIME        NULL,
    `resolution_notes` TEXT           NULL,
    `occurred_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`security_event_id`),
    KEY `idx_security_events_occurred` (`occurred_at`),
    KEY `idx_security_events_type_time` (`event_type`, `occurred_at`),
    KEY `idx_security_events_severity_status` (`severity`, `status`, `occurred_at`),
    KEY `idx_security_events_ip` (`ip_address`, `occurred_at`),
    KEY `idx_security_events_device` (`device_id`, `occurred_at`),
    KEY `idx_security_events_user` (`user_id`, `occurred_at`),
    KEY `idx_security_events_status` (`status`),
    CONSTRAINT `fk_security_events_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_security_events_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_security_events_acknowledged_by`
        FOREIGN KEY (`acknowledged_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Detected suspicious activity, retained permanently for investigation';

-- ---------------------------------------------------------------------------
-- api_request_logs
--
-- One row per API call. Bodies are not stored by default; when the
-- API_LOG_REQUEST_BODY switch is on, the payload is redacted before storage.
-- ---------------------------------------------------------------------------
CREATE TABLE `api_request_logs` (
    `api_request_log_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `request_id`     CHAR(36)     NOT NULL,
    `endpoint`       VARCHAR(255) NOT NULL,
    `route_name`     VARCHAR(80)      NULL,
    `method`         VARCHAR(10)  NOT NULL,
    `user_id`        INT UNSIGNED     NULL,
    `device_id`      INT UNSIGNED     NULL,
    `ip_address`     VARCHAR(45)      NULL,
    `user_agent`     VARCHAR(255)     NULL,
    `status_code`    SMALLINT UNSIGNED NOT NULL,
    `error_code`     VARCHAR(60)      NULL,
    `duration_ms`    DECIMAL(10,2) NOT NULL DEFAULT 0,
    `query_count`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `request_bytes`  INT UNSIGNED NOT NULL DEFAULT 0,
    `response_bytes` INT UNSIGNED NOT NULL DEFAULT 0,
    `payload`        JSON             NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`api_request_log_id`),
    KEY `idx_api_request_logs_created` (`created_at`),
    KEY `idx_api_request_logs_endpoint` (`endpoint`, `created_at`),
    KEY `idx_api_request_logs_device` (`device_id`, `created_at`),
    KEY `idx_api_request_logs_user` (`user_id`, `created_at`),
    KEY `idx_api_request_logs_status` (`status_code`, `created_at`),
    KEY `idx_api_request_logs_request` (`request_id`),
    KEY `idx_api_request_logs_duration` (`duration_ms`),
    CONSTRAINT `fk_api_request_logs_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_api_request_logs_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-request API telemetry for usage review and performance analysis';

-- ---------------------------------------------------------------------------
-- notifications
-- ---------------------------------------------------------------------------
CREATE TABLE `notifications` (
    `notification_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type_key`     VARCHAR(60)  NOT NULL,
    `title`        VARCHAR(150) NOT NULL,
    `description`  VARCHAR(500) NOT NULL,
    `priority`     ENUM('low','normal','high','critical') NOT NULL DEFAULT 'normal',
    `recipient_id` INT UNSIGNED NOT NULL COMMENT 'One row per recipient, so read state is per user',
    `link`         VARCHAR(255)     NULL COMMENT 'Relative path to the related record',
    `icon`         VARCHAR(50)      NULL,
    `related_type` VARCHAR(60)      NULL,
    `related_id`   VARCHAR(40)      NULL,
    `metadata`     JSON             NULL,
    `is_read`      TINYINT(1)   NOT NULL DEFAULT 0,
    `read_at`      DATETIME         NULL,
    `is_archived`  TINYINT(1)   NOT NULL DEFAULT 0,
    `archived_at`  DATETIME         NULL,
    `mail_sent_at` DATETIME         NULL,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`notification_id`),
    KEY `idx_notifications_recipient_unread` (`recipient_id`, `is_read`, `is_archived`, `created_at`),
    KEY `idx_notifications_created` (`created_at`),
    KEY `idx_notifications_type` (`type_key`, `created_at`),
    KEY `idx_notifications_priority` (`priority`, `created_at`),
    CONSTRAINT `fk_notifications_recipient`
        FOREIGN KEY (`recipient_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Per-recipient notification inbox';

-- ---------------------------------------------------------------------------
-- backup_history
-- ---------------------------------------------------------------------------
CREATE TABLE `backup_history` (
    `backup_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `filename`       VARCHAR(180) NOT NULL,
    `backup_type`    ENUM('manual','scheduled','pre_restore') NOT NULL DEFAULT 'manual',
    `scope`          ENUM('full','database','configuration') NOT NULL DEFAULT 'full',
    `file_size`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `checksum`       CHAR(64)         NULL COMMENT 'SHA-256 of the archive, verified before any restore',
    `table_count`    SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `row_count`      BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `compressed`     TINYINT(1)   NOT NULL DEFAULT 1,
    `duration_ms`    INT UNSIGNED NOT NULL DEFAULT 0,
    `status`         ENUM('running','completed','failed','verified','restored','deleted') NOT NULL DEFAULT 'running',
    `verified_at`    DATETIME         NULL,
    `verification_result` VARCHAR(255) NULL,
    `error_message`  TEXT             NULL,
    `created_by`     INT UNSIGNED     NULL,
    `restored_by`    INT UNSIGNED     NULL,
    `restored_at`    DATETIME         NULL,
    `deleted_by`     INT UNSIGNED     NULL,
    `deleted_at`     DATETIME         NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `completed_at`   DATETIME         NULL,
    PRIMARY KEY (`backup_id`),
    UNIQUE KEY `uq_backup_history_filename` (`filename`),
    KEY `idx_backup_history_created` (`created_at`),
    KEY `idx_backup_history_status` (`status`, `created_at`),
    CONSTRAINT `fk_backup_history_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_backup_history_restored_by`
        FOREIGN KEY (`restored_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_backup_history_deleted_by`
        FOREIGN KEY (`deleted_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Backup and restore history with integrity metadata';

-- A denial that also raised a security event links to it.
ALTER TABLE `access_denials`
    ADD CONSTRAINT `fk_access_denials_security_event`
        FOREIGN KEY (`security_event_id`) REFERENCES `security_events` (`security_event_id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

-- @DOWN
ALTER TABLE `access_denials` DROP FOREIGN KEY `fk_access_denials_security_event`;
DROP TABLE IF EXISTS `backup_history`;
DROP TABLE IF EXISTS `notifications`;
DROP TABLE IF EXISTS `api_request_logs`;
DROP TABLE IF EXISTS `security_events`;
DROP TABLE IF EXISTS `error_logs`;
DROP TABLE IF EXISTS `audit_logs`;
