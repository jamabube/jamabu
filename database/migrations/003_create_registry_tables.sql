-- ===========================================================================
-- Migration 003 : Vehicle, driver, owner, RFID and visitor registry
--
-- Relationship decisions worth recording:
--
--   * A vehicle owns its RFID link (vehicles.rfid_tag_id, UNIQUE). Holding the
--     link on one side only keeps the schema in third normal form: there is no
--     second copy of the association that could disagree with the first.
--   * A visitor card's current holder is likewise derived from the open row in
--     visitor_logs rather than duplicated onto rfid_cards.
--   * Fingerprint templates store an identifier and the sensor slot, never a
--     biometric image.
-- ===========================================================================

-- @UP

-- ---------------------------------------------------------------------------
-- vehicle_owners
-- ---------------------------------------------------------------------------
CREATE TABLE `vehicle_owners` (
    `owner_id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `owner_code`      VARCHAR(20)  NOT NULL,
    `first_name`      VARCHAR(60)  NOT NULL,
    `middle_name`     VARCHAR(60)      NULL,
    `last_name`       VARCHAR(60)  NOT NULL,
    `suffix`          VARCHAR(10)      NULL,
    `full_name`       VARCHAR(200)
        GENERATED ALWAYS AS (TRIM(CONCAT_WS(' ', `first_name`, `middle_name`, `last_name`, `suffix`))) STORED,
    `owner_category`  ENUM('employee','resident','contractor','supplier','official','other') NOT NULL DEFAULT 'employee',
    `company`         VARCHAR(120)     NULL,
    `address`         VARCHAR(255)     NULL,
    `contact_number`  VARCHAR(30)      NULL,
    `email`           VARCHAR(150)     NULL,
    `government_id`   VARCHAR(60)      NULL,
    `user_id`         INT UNSIGNED     NULL COMMENT 'Set when the owner is also a system user',
    `department_id`   INT UNSIGNED     NULL,
    `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `remarks`         TEXT             NULL,
    `created_by`      INT UNSIGNED     NULL,
    `updated_by`      INT UNSIGNED     NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME         NULL,
    PRIMARY KEY (`owner_id`),
    UNIQUE KEY `uq_vehicle_owners_code` (`owner_code`),
    KEY `idx_vehicle_owners_name` (`full_name`),
    KEY `idx_vehicle_owners_status` (`status`, `deleted_at`),
    KEY `idx_vehicle_owners_user` (`user_id`),
    CONSTRAINT `fk_vehicle_owners_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_vehicle_owners_department`
        FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_vehicle_owners_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_vehicle_owners_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registered owners of vehicles; they do not use the application directly';

-- ---------------------------------------------------------------------------
-- rfid_tags
--
-- Permanent windshield tags. The UID is stored normalised (upper-case
-- hexadecimal, separators removed) so a reader that formats it differently
-- still matches.
-- ---------------------------------------------------------------------------
CREATE TABLE `rfid_tags` (
    `rfid_tag_id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `rfid_uid`        VARCHAR(32)  NOT NULL COMMENT 'Normalised upper-case hexadecimal UID',
    `tag_code`        VARCHAR(20)  NOT NULL COMMENT 'Human-readable asset code printed on the tag',
    `tag_type`        ENUM('uhf_windshield','uhf_sticker','hf_card','lf_tag') NOT NULL DEFAULT 'uhf_windshield',
    `frequency`       VARCHAR(30)      NULL COMMENT 'e.g. 865-868 MHz',
    `serial_number`   VARCHAR(60)      NULL,
    `status`          ENUM('available','assigned','inactive','lost','damaged','expired','revoked') NOT NULL DEFAULT 'available',
    `activation_date` DATE             NULL,
    `expiration_date` DATE             NULL COMMENT 'NULL means the tag does not expire',
    `last_scanned_at` DATETIME         NULL,
    `scan_count`      INT UNSIGNED NOT NULL DEFAULT 0,
    `remarks`         TEXT             NULL,
    `created_by`      INT UNSIGNED     NULL,
    `updated_by`      INT UNSIGNED     NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME         NULL,
    PRIMARY KEY (`rfid_tag_id`),
    UNIQUE KEY `uq_rfid_tags_uid` (`rfid_uid`),
    UNIQUE KEY `uq_rfid_tags_code` (`tag_code`),
    KEY `idx_rfid_tags_status` (`status`, `deleted_at`),
    KEY `idx_rfid_tags_expiration` (`expiration_date`),
    KEY `idx_rfid_tags_last_scanned` (`last_scanned_at`),
    CONSTRAINT `fk_rfid_tags_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_rfid_tags_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Permanent RFID windshield tags';

-- ---------------------------------------------------------------------------
-- drivers
-- ---------------------------------------------------------------------------
CREATE TABLE `drivers` (
    `driver_id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `driver_code`       VARCHAR(20)  NOT NULL,
    `first_name`        VARCHAR(60)  NOT NULL,
    `middle_name`       VARCHAR(60)      NULL,
    `last_name`         VARCHAR(60)  NOT NULL,
    `suffix`            VARCHAR(10)      NULL,
    `full_name`         VARCHAR(200)
        GENERATED ALWAYS AS (TRIM(CONCAT_WS(' ', `first_name`, `middle_name`, `last_name`, `suffix`))) STORED,
    `address`           VARCHAR(255)     NULL,
    `birth_date`        DATE             NULL,
    `gender`            ENUM('male','female','other','undisclosed') NOT NULL DEFAULT 'undisclosed',
    `civil_status`      ENUM('single','married','widowed','separated','undisclosed') NOT NULL DEFAULT 'undisclosed',
    `contact_number`    VARCHAR(30)      NULL,
    `email`             VARCHAR(150)     NULL,
    `government_id`     VARCHAR(60)      NULL COMMENT 'Driving licence or government identification number',
    `licence_expiry`    DATE             NULL,
    `emergency_contact_name`   VARCHAR(120) NULL,
    `emergency_contact_number` VARCHAR(30)  NULL,
    `photo`             VARCHAR(120)     NULL,
    `owner_id`          INT UNSIGNED     NULL COMMENT 'Set when the driver is also a registered owner',
    `user_id`           INT UNSIGNED     NULL,
    `status`            ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    `remarks`           TEXT             NULL,
    `created_by`        INT UNSIGNED     NULL,
    `updated_by`        INT UNSIGNED     NULL,
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`        DATETIME         NULL,
    PRIMARY KEY (`driver_id`),
    UNIQUE KEY `uq_drivers_code` (`driver_code`),
    KEY `idx_drivers_name` (`full_name`),
    KEY `idx_drivers_status` (`status`, `deleted_at`),
    KEY `idx_drivers_owner` (`owner_id`),
    CONSTRAINT `fk_drivers_owner`
        FOREIGN KEY (`owner_id`) REFERENCES `vehicle_owners` (`owner_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_drivers_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_drivers_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_drivers_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Authorised drivers of registered vehicles';

-- ---------------------------------------------------------------------------
-- vehicles
-- ---------------------------------------------------------------------------
CREATE TABLE `vehicles` (
    `vehicle_id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `vehicle_code`      VARCHAR(20)  NOT NULL,
    `plate_number`      VARCHAR(20)  NOT NULL COMMENT 'Stored normalised: upper case, single spaces',
    `rfid_tag_id`       INT UNSIGNED     NULL COMMENT 'Authoritative tag association; UNIQUE enforces one tag per vehicle',
    `vehicle_type_id`   INT UNSIGNED NOT NULL,
    `brand`             VARCHAR(60)      NULL,
    `model`             VARCHAR(60)      NULL,
    `colour`            VARCHAR(40)      NULL,
    `year_model`        SMALLINT UNSIGNED NULL,
    `chassis_number`    VARCHAR(60)      NULL,
    `engine_number`     VARCHAR(60)      NULL,
    `owner_id`          INT UNSIGNED NOT NULL,
    `driver_id`         INT UNSIGNED     NULL,
    `registration_date` DATE             NULL,
    `insurance_provider` VARCHAR(120)    NULL,
    `insurance_expiry`  DATE             NULL,
    `photo`             VARCHAR(120)     NULL,
    `status`            ENUM('active','inactive','suspended','archived') NOT NULL DEFAULT 'active',
    `remarks`           TEXT             NULL,
    `created_by`        INT UNSIGNED     NULL,
    `updated_by`        INT UNSIGNED     NULL,
    `deleted_by`        INT UNSIGNED     NULL,
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`        DATETIME         NULL,
    PRIMARY KEY (`vehicle_id`),
    UNIQUE KEY `uq_vehicles_code` (`vehicle_code`),
    UNIQUE KEY `uq_vehicles_plate` (`plate_number`),
    UNIQUE KEY `uq_vehicles_rfid_tag` (`rfid_tag_id`),
    KEY `idx_vehicles_owner` (`owner_id`),
    KEY `idx_vehicles_driver` (`driver_id`),
    KEY `idx_vehicles_type` (`vehicle_type_id`),
    KEY `idx_vehicles_status` (`status`, `deleted_at`),
    CONSTRAINT `fk_vehicles_rfid_tag`
        FOREIGN KEY (`rfid_tag_id`) REFERENCES `rfid_tags` (`rfid_tag_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_vehicles_type`
        FOREIGN KEY (`vehicle_type_id`) REFERENCES `vehicle_types` (`vehicle_type_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_vehicles_owner`
        FOREIGN KEY (`owner_id`) REFERENCES `vehicle_owners` (`owner_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_vehicles_driver`
        FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_vehicles_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_vehicles_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_vehicles_deleted_by`
        FOREIGN KEY (`deleted_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Registered vehicles authorised to enter the park';

-- ---------------------------------------------------------------------------
-- visitors
-- ---------------------------------------------------------------------------
CREATE TABLE `visitors` (
    `visitor_id`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `visitor_code`    VARCHAR(20)  NOT NULL,
    `first_name`      VARCHAR(60)  NOT NULL,
    `middle_name`     VARCHAR(60)      NULL,
    `last_name`       VARCHAR(60)  NOT NULL,
    `full_name`       VARCHAR(200)
        GENERATED ALWAYS AS (TRIM(CONCAT_WS(' ', `first_name`, `middle_name`, `last_name`))) STORED,
    `visitor_type_id` INT UNSIGNED     NULL,
    `company`         VARCHAR(120)     NULL,
    `contact_number`  VARCHAR(30)      NULL,
    `email`           VARCHAR(150)     NULL,
    `address`         VARCHAR(255)     NULL,
    `government_id`   VARCHAR(60)      NULL,
    `photo`           VARCHAR(120)     NULL,
    `is_blacklisted`  TINYINT(1)   NOT NULL DEFAULT 0,
    `blacklist_reason` VARCHAR(255)    NULL,
    `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `remarks`         TEXT             NULL,
    `created_by`      INT UNSIGNED     NULL,
    `updated_by`      INT UNSIGNED     NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME         NULL,
    PRIMARY KEY (`visitor_id`),
    UNIQUE KEY `uq_visitors_code` (`visitor_code`),
    KEY `idx_visitors_name` (`full_name`),
    KEY `idx_visitors_status` (`status`, `deleted_at`),
    KEY `idx_visitors_blacklist` (`is_blacklisted`),
    CONSTRAINT `fk_visitors_type`
        FOREIGN KEY (`visitor_type_id`) REFERENCES `visitor_types` (`visitor_type_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_visitors_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_visitors_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='People issued temporary access, retained so repeat visits reuse one record';

-- ---------------------------------------------------------------------------
-- rfid_cards
--
-- Reusable temporary cards. A card is a physical asset with a lifecycle of its
-- own; who currently holds it is recorded on the open visitor_logs row.
-- ---------------------------------------------------------------------------
CREATE TABLE `rfid_cards` (
    `rfid_card_id`    INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `card_uid`        VARCHAR(32)  NOT NULL COMMENT 'Normalised upper-case hexadecimal UID',
    `card_code`       VARCHAR(20)  NOT NULL COMMENT 'Number printed on the card, e.g. V-014',
    `card_type`       ENUM('hf_card','uhf_card','keyfob') NOT NULL DEFAULT 'hf_card',
    `status`          ENUM('available','issued','inactive','lost','damaged','retired') NOT NULL DEFAULT 'available',
    `issued_count`    INT UNSIGNED NOT NULL DEFAULT 0,
    `last_issued_at`  DATETIME         NULL,
    `last_scanned_at` DATETIME         NULL,
    `remarks`         TEXT             NULL,
    `created_by`      INT UNSIGNED     NULL,
    `updated_by`      INT UNSIGNED     NULL,
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`      DATETIME         NULL,
    PRIMARY KEY (`rfid_card_id`),
    UNIQUE KEY `uq_rfid_cards_uid` (`card_uid`),
    UNIQUE KEY `uq_rfid_cards_code` (`card_code`),
    KEY `idx_rfid_cards_status` (`status`, `deleted_at`),
    CONSTRAINT `fk_rfid_cards_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_rfid_cards_updated_by`
        FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Reusable temporary RFID cards issued to visitors';

-- ---------------------------------------------------------------------------
-- visitor_logs
--
-- One row per issued pass, which is also the visit record: the pass carries the
-- purpose, the authoriser, the validity window and the entry/exit stamps.
-- ---------------------------------------------------------------------------
CREATE TABLE `visitor_logs` (
    `visitor_log_id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `pass_reference`     VARCHAR(24)  NOT NULL,
    `visitor_id`         INT UNSIGNED NOT NULL,
    `rfid_card_id`       INT UNSIGNED     NULL,
    `purpose`            VARCHAR(255) NOT NULL,
    `destination`        VARCHAR(120)     NULL COMMENT 'Area or office being visited',
    `vehicle_plate`      VARCHAR(20)      NULL,
    `vehicle_description` VARCHAR(120)    NULL,
    `companions`         SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `authorized_by`      INT UNSIGNED     NULL,
    `issued_by`          INT UNSIGNED     NULL,
    `issued_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `valid_from`         DATETIME     NOT NULL,
    `valid_until`        DATETIME     NOT NULL,
    `entry_time`         DATETIME         NULL,
    `exit_time`          DATETIME         NULL,
    `status`             ENUM('issued','inside','completed','expired','revoked') NOT NULL DEFAULT 'issued',
    `revoked_by`         INT UNSIGNED     NULL,
    `revoked_at`         DATETIME         NULL,
    `revoke_reason`      VARCHAR(255)     NULL,
    `remarks`            TEXT             NULL,
    `created_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`visitor_log_id`),
    UNIQUE KEY `uq_visitor_logs_reference` (`pass_reference`),
    KEY `idx_visitor_logs_visitor` (`visitor_id`, `issued_at`),
    KEY `idx_visitor_logs_card_status` (`rfid_card_id`, `status`),
    KEY `idx_visitor_logs_status_validity` (`status`, `valid_until`),
    KEY `idx_visitor_logs_entry` (`entry_time`),
    KEY `idx_visitor_logs_issued` (`issued_at`),
    CONSTRAINT `fk_visitor_logs_visitor`
        FOREIGN KEY (`visitor_id`) REFERENCES `visitors` (`visitor_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_visitor_logs_card`
        FOREIGN KEY (`rfid_card_id`) REFERENCES `rfid_cards` (`rfid_card_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_visitor_logs_authorized_by`
        FOREIGN KEY (`authorized_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_visitor_logs_issued_by`
        FOREIGN KEY (`issued_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_visitor_logs_revoked_by`
        FOREIGN KEY (`revoked_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Temporary visitor passes and the visit they authorise';

-- ---------------------------------------------------------------------------
-- fingerprint_templates
--
-- Biometric metadata only. The system records which sensor slot holds an
-- enrolment and a non-reversible checksum used to detect a slot that has been
-- overwritten; no image and no reconstructable template is ever stored.
-- ---------------------------------------------------------------------------
CREATE TABLE `fingerprint_templates` (
    `template_id`       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `template_number`   VARCHAR(24)  NOT NULL COMMENT 'Application-level identifier, e.g. FP-000014',
    `device_id`         INT UNSIGNED     NULL COMMENT 'Sensor the enrolment lives on; set in migration 004',
    `sensor_slot`       SMALLINT UNSIGNED NOT NULL COMMENT 'Storage position inside the fingerprint module',
    `finger_label`      VARCHAR(30)      NULL COMMENT 'e.g. right_thumb',
    `assigned_user_id`  INT UNSIGNED     NULL,
    `assigned_driver_id` INT UNSIGNED    NULL,
    `checksum`          CHAR(64)         NULL COMMENT 'SHA-256 of the sensor-reported template signature; never the template itself',
    `quality_score`     TINYINT UNSIGNED NULL COMMENT 'Enrolment quality reported by the sensor, 0-100',
    `enrolled_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `enrolled_by`       INT UNSIGNED     NULL,
    `last_verified_at`  DATETIME         NULL,
    `verification_count` INT UNSIGNED NOT NULL DEFAULT 0,
    `failure_count`     INT UNSIGNED NOT NULL DEFAULT 0,
    `synchronised_at`   DATETIME         NULL COMMENT 'Last time the server confirmed the slot still matches the sensor',
    `status`            ENUM('active','inactive','pending_sync','revoked') NOT NULL DEFAULT 'active',
    `remarks`           VARCHAR(255)     NULL,
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`        DATETIME         NULL,
    PRIMARY KEY (`template_id`),
    UNIQUE KEY `uq_fingerprint_templates_number` (`template_number`),
    KEY `idx_fingerprint_templates_user` (`assigned_user_id`, `status`),
    KEY `idx_fingerprint_templates_driver` (`assigned_driver_id`, `status`),
    KEY `idx_fingerprint_templates_slot` (`device_id`, `sensor_slot`),
    KEY `idx_fingerprint_templates_status` (`status`, `deleted_at`),
    CONSTRAINT `fk_fingerprint_templates_user`
        FOREIGN KEY (`assigned_user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_fingerprint_templates_driver`
        FOREIGN KEY (`assigned_driver_id`) REFERENCES `drivers` (`driver_id`) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_fingerprint_templates_enrolled_by`
        FOREIGN KEY (`enrolled_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Biometric enrolment metadata; never a fingerprint image';

-- The primary-template shortcuts are added once the template table exists.
ALTER TABLE `users`
    ADD COLUMN `fingerprint_template_id` INT UNSIGNED NULL
        COMMENT 'Primary enrolment used for station authentication' AFTER `profile_picture`,
    ADD KEY `idx_users_fingerprint` (`fingerprint_template_id`),
    ADD CONSTRAINT `fk_users_fingerprint_template`
        FOREIGN KEY (`fingerprint_template_id`) REFERENCES `fingerprint_templates` (`template_id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `drivers`
    ADD COLUMN `fingerprint_template_id` INT UNSIGNED NULL AFTER `photo`,
    ADD KEY `idx_drivers_fingerprint` (`fingerprint_template_id`),
    ADD CONSTRAINT `fk_drivers_fingerprint_template`
        FOREIGN KEY (`fingerprint_template_id`) REFERENCES `fingerprint_templates` (`template_id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

-- @DOWN
ALTER TABLE `drivers` DROP FOREIGN KEY `fk_drivers_fingerprint_template`;
ALTER TABLE `users` DROP FOREIGN KEY `fk_users_fingerprint_template`;
ALTER TABLE `drivers` DROP COLUMN `fingerprint_template_id`;
ALTER TABLE `users` DROP COLUMN `fingerprint_template_id`;
DROP TABLE IF EXISTS `fingerprint_templates`;
DROP TABLE IF EXISTS `visitor_logs`;
DROP TABLE IF EXISTS `rfid_cards`;
DROP TABLE IF EXISTS `visitors`;
DROP TABLE IF EXISTS `vehicles`;
DROP TABLE IF EXISTS `drivers`;
DROP TABLE IF EXISTS `rfid_tags`;
DROP TABLE IF EXISTS `vehicle_owners`;
