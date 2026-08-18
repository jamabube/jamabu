-- ===========================================================================
-- Migration 004 : IoT device, heartbeat and station-operator tables
--
-- Only devices with a row in `devices` may communicate with the server. The
-- API key is stored as a hash, exactly like a password: a leaked database must
-- not hand an attacker a working device credential.
-- ===========================================================================

-- @UP

-- ---------------------------------------------------------------------------
-- devices
-- ---------------------------------------------------------------------------
CREATE TABLE `devices` (
    `device_id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_code`          VARCHAR(40)  NOT NULL COMMENT 'Identifier the firmware transmits, e.g. ESP32-ENTRY-01',
    `device_name`          VARCHAR(120) NOT NULL,
    `description`          VARCHAR(255)     NULL,
    `api_key_hash`         VARCHAR(255) NOT NULL COMMENT 'Hash of the API key; the plain value is shown once at issue',
    `api_key_prefix`       VARCHAR(12)  NOT NULL COMMENT 'First characters, so a key can be identified without revealing it',
    `api_key_issued_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `api_key_rotated_at`   DATETIME         NULL,
    `api_key_rotated_by`   INT UNSIGNED     NULL,
    `signing_secret_hash`  VARCHAR(255)     NULL COMMENT 'Hash of the HMAC secret used for request signatures',
    `mac_address`          VARCHAR(17)      NULL,
    `ip_address`           VARCHAR(45)      NULL,
    `allowed_ip`           VARCHAR(45)      NULL COMMENT 'When set, the device may only call from this address',
    `firmware_version`     VARCHAR(20)      NULL,
    `previous_firmware`    VARCHAR(20)      NULL,
    `firmware_updated_at`  DATETIME         NULL,
    `location`             VARCHAR(120)     NULL,
    `gate_type`            ENUM('entry','exit','both') NOT NULL DEFAULT 'both'
        COMMENT 'Restricts which transactions this station may record',
    `gate_label`           VARCHAR(60)      NULL COMMENT 'e.g. Main Gate — Entry Lane',
    `installation_date`    DATE             NULL,
    `heartbeat_interval`   SMALLINT UNSIGNED NOT NULL DEFAULT 30,
    `last_heartbeat_at`    DATETIME         NULL,
    `last_communication_at` DATETIME        NULL,
    `last_scan_at`         DATETIME         NULL,
    `signal_strength`      SMALLINT         NULL COMMENT 'RSSI in dBm, typically -30 to -95',
    `uptime_seconds`       INT UNSIGNED     NULL,
    `restart_count`        INT UNSIGNED NOT NULL DEFAULT 0,
    `communication_count`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `error_count`          INT UNSIGNED NOT NULL DEFAULT 0,
    `auth_failure_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `health_score`         TINYINT UNSIGNED NULL COMMENT 'Computed 0-100; see config/monitoring.php for the weighting',
    `status`               ENUM('active','inactive','maintenance','suspended','decommissioned') NOT NULL DEFAULT 'active',
    `suspended_until`      DATETIME         NULL,
    `suspend_reason`       VARCHAR(255)     NULL,
    `remarks`              TEXT             NULL,
    `created_by`           INT UNSIGNED     NULL,
    `updated_by`           INT UNSIGNED     NULL,
    `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`           DATETIME         NULL,
    PRIMARY KEY (`device_id`),
    UNIQUE KEY `uq_devices_code` (`device_code`),
    UNIQUE KEY `uq_devices_mac` (`mac_address`),
    KEY `idx_devices_status` (`status`, `deleted_at`),
    KEY `idx_devices_heartbeat` (`last_heartbeat_at`),
    KEY `idx_devices_gate` (`gate_type`, `status`),
    CONSTRAINT `fk_devices_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_devices_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_devices_key_rotated_by`
        FOREIGN KEY (`api_key_rotated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registered ESP32 monitoring stations; unknown devices are rejected';

-- ---------------------------------------------------------------------------
-- device_heartbeats
--
-- Time-series health telemetry. Pruned by retention policy rather than kept
-- forever: the aggregate health score on `devices` is the long-lived record.
-- ---------------------------------------------------------------------------
CREATE TABLE `device_heartbeats` (
    `heartbeat_id`     BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`        INT UNSIGNED NOT NULL,
    `firmware_version` VARCHAR(20)      NULL,
    `ip_address`       VARCHAR(45)      NULL,
    `signal_strength`  SMALLINT         NULL,
    `free_heap_bytes`  INT UNSIGNED     NULL,
    `heap_total_bytes` INT UNSIGNED     NULL,
    `memory_usage_pct` DECIMAL(5,2)     NULL,
    `cpu_usage_pct`    DECIMAL(5,2)     NULL,
    `temperature_c`    DECIMAL(5,2)     NULL,
    `battery_level_pct` TINYINT UNSIGNED NULL,
    `uptime_seconds`   INT UNSIGNED     NULL,
    `queued_requests`  SMALLINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT 'Transactions the device is holding because the server was unreachable',
    `last_scan_at`     DATETIME         NULL,
    `last_verification_at` DATETIME     NULL,
    `reported_status`  VARCHAR(30)      NULL COMMENT 'ready, scanning, degraded, error',
    `received_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`heartbeat_id`),
    KEY `idx_device_heartbeats_device_time` (`device_id`, `received_at`),
    KEY `idx_device_heartbeats_time` (`received_at`),
    CONSTRAINT `fk_device_heartbeats_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Periodic device health telemetry';

-- ---------------------------------------------------------------------------
-- operator_sessions
--
-- A guard authenticates at the station with a fingerprint before monitoring
-- begins. This table records who is accountable for the transactions recorded
-- at a given device during a given window, which is the accountability the
-- manual logbook could not provide.
-- ---------------------------------------------------------------------------
CREATE TABLE `operator_sessions` (
    `operator_session_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`           INT UNSIGNED NOT NULL,
    `user_id`             INT UNSIGNED NOT NULL,
    `template_id`         INT UNSIGNED     NULL,
    `authenticated_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`          DATETIME     NOT NULL COMMENT 'Re-authentication is required after this time',
    `last_activity_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `ended_at`            DATETIME         NULL,
    `end_reason`          ENUM('signed_out','expired','superseded','device_restart','administrator') NULL,
    `transaction_count`   INT UNSIGNED NOT NULL DEFAULT 0,
    `match_score`         SMALLINT UNSIGNED NULL COMMENT 'Confidence reported by the sensor',
    `status`              ENUM('active','ended') NOT NULL DEFAULT 'active',
    PRIMARY KEY (`operator_session_id`),
    KEY `idx_operator_sessions_device_status` (`device_id`, `status`, `expires_at`),
    KEY `idx_operator_sessions_user` (`user_id`, `authenticated_at`),
    CONSTRAINT `fk_operator_sessions_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_operator_sessions_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_operator_sessions_template`
        FOREIGN KEY (`template_id`) REFERENCES `fingerprint_templates` (`template_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Guardhouse operator shifts authenticated by fingerprint';

-- ---------------------------------------------------------------------------
-- fingerprint_verifications
--
-- Every verification attempt, successful or not. Feeds the fingerprint history
-- view and the failed-verification security events.
-- ---------------------------------------------------------------------------
CREATE TABLE `fingerprint_verifications` (
    `verification_id`  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`        INT UNSIGNED     NULL,
    `template_id`      INT UNSIGNED     NULL,
    `user_id`          INT UNSIGNED     NULL,
    `driver_id`        INT UNSIGNED     NULL,
    `sensor_slot`      SMALLINT UNSIGNED NULL COMMENT 'Slot the sensor reported, even when it maps to no enrolment',
    `purpose`          ENUM('operator_login','driver_verification','enrolment_check') NOT NULL DEFAULT 'operator_login',
    `successful`       TINYINT(1)   NOT NULL DEFAULT 0,
    `match_score`      SMALLINT UNSIGNED NULL,
    `failure_reason`   VARCHAR(60)      NULL,
    `verified_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`verification_id`),
    KEY `idx_fingerprint_verifications_template` (`template_id`, `verified_at`),
    KEY `idx_fingerprint_verifications_device` (`device_id`, `verified_at`),
    KEY `idx_fingerprint_verifications_user` (`user_id`, `verified_at`),
    KEY `idx_fingerprint_verifications_result` (`successful`, `verified_at`),
    CONSTRAINT `fk_fingerprint_verifications_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_fingerprint_verifications_template`
        FOREIGN KEY (`template_id`) REFERENCES `fingerprint_templates` (`template_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_fingerprint_verifications_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_fingerprint_verifications_driver`
        FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='History of every fingerprint verification attempt';

-- Fingerprint enrolments live on a specific sensor; the link is added here
-- because migration 003 runs before the devices table exists.
ALTER TABLE `fingerprint_templates`
    ADD CONSTRAINT `fk_fingerprint_templates_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

-- @DOWN
ALTER TABLE `fingerprint_templates` DROP FOREIGN KEY `fk_fingerprint_templates_device`;
DROP TABLE IF EXISTS `fingerprint_verifications`;
DROP TABLE IF EXISTS `operator_sessions`;
DROP TABLE IF EXISTS `device_heartbeats`;
DROP TABLE IF EXISTS `devices`;
