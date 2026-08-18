-- ===========================================================================
-- Migration 005 : Vehicle access monitoring
--
-- Two tables, deliberately separated:
--
--   vehicle_access_logs  One row per *visit*. It is created when a vehicle is
--                        granted entry and completed when that vehicle is
--                        granted exit, which is what makes "every entry must
--                        eventually have one exit" a property the schema can
--                        express rather than a rule the code merely hopes for.
--                        Only granted transactions appear here, so the table is
--                        the official monitoring record.
--
--   access_denials       One row per rejected scan. The specification requires
--                        that an unregistered tag creates no access record, but
--                        the rejection must still be investigable and must feed
--                        the rejected-entry analytics. Keeping denials in their
--                        own table satisfies both: the monitoring history stays
--                        clean, and no evidence is lost.
-- ===========================================================================

-- @UP

-- ---------------------------------------------------------------------------
-- vehicle_access_logs
-- ---------------------------------------------------------------------------
CREATE TABLE `vehicle_access_logs` (
    `access_log_id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `transaction_reference` VARCHAR(24) NOT NULL COMMENT 'Human-quotable reference printed on receipts and reports',

    -- Subject of the movement. Exactly one of vehicle_id / visitor_log_id is
    -- populated; the CHECK constraint below enforces it.
    `vehicle_id`           INT UNSIGNED     NULL,
    `visitor_log_id`       INT UNSIGNED     NULL,
    `driver_id`            INT UNSIGNED     NULL,
    `rfid_tag_id`          INT UNSIGNED     NULL,
    `rfid_card_id`         INT UNSIGNED     NULL,
    `scanned_uid`          VARCHAR(32)  NOT NULL COMMENT 'UID as read, retained even if the tag is later reassigned',
    `plate_number`         VARCHAR(20)      NULL COMMENT 'Snapshot at the time of the movement',

    -- Entry side.
    `entry_device_id`      INT UNSIGNED     NULL,
    `entry_time`           DATETIME     NOT NULL,
    `entry_operator_id`    INT UNSIGNED     NULL COMMENT 'Guard authenticated at the station',
    `entry_operator_session_id` BIGINT UNSIGNED NULL,
    `entry_verification`   ENUM('rfid','rfid+fingerprint','manual','visitor_card') NOT NULL DEFAULT 'rfid',
    `entry_request_id`     CHAR(36)         NULL COMMENT 'Correlates the record with the API request log',

    -- Exit side, filled in when the vehicle leaves.
    `exit_device_id`       INT UNSIGNED     NULL,
    `exit_time`            DATETIME         NULL,
    `exit_operator_id`     INT UNSIGNED     NULL,
    `exit_operator_session_id` BIGINT UNSIGNED NULL,
    `exit_verification`    ENUM('rfid','rfid+fingerprint','manual','visitor_card') NULL,
    `exit_request_id`      CHAR(36)         NULL,

    -- Derived so reports can filter and sort on stay duration without
    -- recomputing it per row.
    `duration_seconds`     INT UNSIGNED
        GENERATED ALWAYS AS (
            CASE WHEN `exit_time` IS NULL THEN NULL
                 ELSE TIMESTAMPDIFF(SECOND, `entry_time`, `exit_time`) END
        ) STORED,

    -- "A vehicle cannot enter twice without first exiting" is the central
    -- business rule of the whole system, so it is enforced by the database
    -- rather than only by the service that normally writes here. The key holds
    -- the vehicle id only while the visit is open and NULL once it closes; a
    -- unique index over it therefore permits any number of completed visits but
    -- exactly one open visit per vehicle. MySQL ignores NULLs in a unique
    -- index, which is precisely the behaviour this needs.
    `open_vehicle_key`     INT UNSIGNED
        GENERATED ALWAYS AS (CASE WHEN `status` = 'inside' THEN `vehicle_id` ELSE NULL END) STORED,
    `open_visitor_key`     INT UNSIGNED
        GENERATED ALWAYS AS (CASE WHEN `status` = 'inside' THEN `visitor_log_id` ELSE NULL END) STORED,

    `access_type`          ENUM('entry','exit') NOT NULL DEFAULT 'entry'
        COMMENT 'The most recent transaction recorded against this visit',
    `status`               ENUM('inside','completed','force_closed') NOT NULL DEFAULT 'inside',
    `result`               VARCHAR(40)  NOT NULL DEFAULT 'granted',
    `is_visitor`           TINYINT(1)   NOT NULL DEFAULT 0,
    `remarks`              TEXT             NULL,

    -- Administrative annotation. The original record is never edited; an
    -- annotation is added alongside it.
    `annotation`           TEXT             NULL,
    `annotated_by`         INT UNSIGNED     NULL,
    `annotated_at`         DATETIME         NULL,

    `force_closed_by`      INT UNSIGNED     NULL,
    `force_close_reason`   VARCHAR(255)     NULL,

    `created_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (`access_log_id`),
    UNIQUE KEY `uq_access_logs_reference` (`transaction_reference`),
    UNIQUE KEY `uq_access_logs_open_vehicle` (`open_vehicle_key`),
    UNIQUE KEY `uq_access_logs_open_visitor` (`open_visitor_key`),

    -- The dashboard's hottest queries: "who is inside", "today's movements",
    -- "this vehicle's history".
    KEY `idx_access_logs_status_entry` (`status`, `entry_time`),
    KEY `idx_access_logs_vehicle_status` (`vehicle_id`, `status`),
    KEY `idx_access_logs_vehicle_entry` (`vehicle_id`, `entry_time`),
    KEY `idx_access_logs_entry_time` (`entry_time`),
    KEY `idx_access_logs_exit_time` (`exit_time`),
    KEY `idx_access_logs_entry_device` (`entry_device_id`, `entry_time`),
    KEY `idx_access_logs_exit_device` (`exit_device_id`, `exit_time`),
    KEY `idx_access_logs_driver` (`driver_id`, `entry_time`),
    KEY `idx_access_logs_operator` (`entry_operator_id`, `entry_time`),
    KEY `idx_access_logs_visitor` (`visitor_log_id`),
    KEY `idx_access_logs_uid` (`scanned_uid`, `entry_time`),
    KEY `idx_access_logs_plate` (`plate_number`),
    KEY `idx_access_logs_duration` (`duration_seconds`),

    -- These two carry no referential action on purpose. Both are surrogate
    -- auto-increment keys that are never updated, so ON UPDATE CASCADE would
    -- buy nothing -- and MySQL refuses to let a column with a referential
    -- action appear in a CHECK constraint or a generated column, both of which
    -- this table relies on to enforce its core business rules. Deletion is
    -- still blocked: the default is NO ACTION.
    CONSTRAINT `fk_access_logs_vehicle`
        FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`),
    CONSTRAINT `fk_access_logs_visitor_log`
        FOREIGN KEY (`visitor_log_id`) REFERENCES `visitor_logs` (`visitor_log_id`),
    CONSTRAINT `fk_access_logs_driver`
        FOREIGN KEY (`driver_id`) REFERENCES `drivers` (`driver_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_access_logs_rfid_tag`
        FOREIGN KEY (`rfid_tag_id`) REFERENCES `rfid_tags` (`rfid_tag_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_access_logs_rfid_card`
        FOREIGN KEY (`rfid_card_id`) REFERENCES `rfid_cards` (`rfid_card_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_access_logs_entry_device`
        FOREIGN KEY (`entry_device_id`) REFERENCES `devices` (`device_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_access_logs_exit_device`
        FOREIGN KEY (`exit_device_id`) REFERENCES `devices` (`device_id`) ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_access_logs_entry_operator`
        FOREIGN KEY (`entry_operator_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_access_logs_exit_operator`
        FOREIGN KEY (`exit_operator_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_access_logs_annotated_by`
        FOREIGN KEY (`annotated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_access_logs_force_closed_by`
        FOREIGN KEY (`force_closed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,

    -- A movement belongs to a registered vehicle or to a visitor pass, never
    -- to neither and never to both.
    CONSTRAINT `chk_access_logs_subject`
        CHECK ((`vehicle_id` IS NOT NULL) <> (`visitor_log_id` IS NOT NULL)),
    -- A vehicle cannot leave before it arrived.
    CONSTRAINT `chk_access_logs_chronology`
        CHECK (`exit_time` IS NULL OR `exit_time` >= `entry_time`),
    -- A completed visit must record an exit; an open one must not.
    CONSTRAINT `chk_access_logs_status`
        CHECK ((`status` = 'inside' AND `exit_time` IS NULL) OR (`status` <> 'inside' AND `exit_time` IS NOT NULL))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Official monitoring record: one row per vehicle visit';

-- ---------------------------------------------------------------------------
-- access_denials
--
-- Every scan that did not become a monitoring record, with the reason.
-- ---------------------------------------------------------------------------
CREATE TABLE `access_denials` (
    `denial_id`        BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `device_id`        INT UNSIGNED     NULL,
    `scanned_uid`      VARCHAR(32)  NOT NULL,
    `attempted_type`   ENUM('entry','exit') NOT NULL,
    `reason_code`      VARCHAR(40)  NOT NULL COMMENT 'Matches the result codes in config/monitoring.php',
    `reason`           VARCHAR(255) NOT NULL,

    -- Populated when the scan resolved to a known record before being rejected;
    -- left NULL for a genuinely unknown tag.
    `vehicle_id`       INT UNSIGNED     NULL,
    `rfid_tag_id`      INT UNSIGNED     NULL,
    `rfid_card_id`     INT UNSIGNED     NULL,
    `visitor_log_id`   INT UNSIGNED     NULL,
    `plate_number`     VARCHAR(20)      NULL,

    `operator_id`      INT UNSIGNED     NULL,
    `ip_address`       VARCHAR(45)      NULL,
    `request_id`       CHAR(36)         NULL,
    `security_event_id` BIGINT UNSIGNED NULL COMMENT 'Set in migration 006 once security_events exists',
    `occurred_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`denial_id`),
    KEY `idx_access_denials_time` (`occurred_at`),
    KEY `idx_access_denials_reason` (`reason_code`, `occurred_at`),
    KEY `idx_access_denials_device` (`device_id`, `occurred_at`),
    KEY `idx_access_denials_uid` (`scanned_uid`, `occurred_at`),
    KEY `idx_access_denials_vehicle` (`vehicle_id`, `occurred_at`),
    CONSTRAINT `fk_access_denials_device`
        FOREIGN KEY (`device_id`) REFERENCES `devices` (`device_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_access_denials_vehicle`
        FOREIGN KEY (`vehicle_id`) REFERENCES `vehicles` (`vehicle_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_access_denials_rfid_tag`
        FOREIGN KEY (`rfid_tag_id`) REFERENCES `rfid_tags` (`rfid_tag_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_access_denials_rfid_card`
        FOREIGN KEY (`rfid_card_id`) REFERENCES `rfid_cards` (`rfid_card_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_access_denials_visitor_log`
        FOREIGN KEY (`visitor_log_id`) REFERENCES `visitor_logs` (`visitor_log_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_access_denials_operator`
        FOREIGN KEY (`operator_id`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Rejected scans, retained for investigation and analytics';

-- Operator-session links are added now that both tables exist.
ALTER TABLE `vehicle_access_logs`
    ADD CONSTRAINT `fk_access_logs_entry_operator_session`
        FOREIGN KEY (`entry_operator_session_id`) REFERENCES `operator_sessions` (`operator_session_id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_access_logs_exit_operator_session`
        FOREIGN KEY (`exit_operator_session_id`) REFERENCES `operator_sessions` (`operator_session_id`)
        ON DELETE SET NULL ON UPDATE CASCADE;

-- ---------------------------------------------------------------------------
-- Entry immutability
--
-- The subject rule and the chronology rule are enforced declaratively by the
-- CHECK constraints above, and "one open visit per vehicle" by the unique
-- indexes over the generated keys. What no constraint can express is that a
-- completed movement's entry side must never be rewritten: an access log is
-- the system's evidence of what happened, and a correction belongs in the
-- annotation column beside it, not on top of it.
-- ---------------------------------------------------------------------------
DROP TRIGGER IF EXISTS `trg_access_logs_entry_immutable`;

DELIMITER $$
CREATE TRIGGER `trg_access_logs_entry_immutable`
BEFORE UPDATE ON `vehicle_access_logs`
FOR EACH ROW
BEGIN
    IF NOT (OLD.`entry_time` <=> NEW.`entry_time`)
       OR NOT (OLD.`vehicle_id` <=> NEW.`vehicle_id`)
       OR NOT (OLD.`visitor_log_id` <=> NEW.`visitor_log_id`)
       OR NOT (OLD.`entry_device_id` <=> NEW.`entry_device_id`)
       OR NOT (OLD.`scanned_uid` <=> NEW.`scanned_uid`)
       OR NOT (OLD.`transaction_reference` <=> NEW.`transaction_reference`) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The entry details of a monitoring record are immutable; record a correction as an annotation instead.';
    END IF;

    -- An exit may be recorded once. Rewriting one that already exists would
    -- silently change a stay duration that has already been reported on.
    IF OLD.`exit_time` IS NOT NULL AND NOT (OLD.`exit_time` <=> NEW.`exit_time`) THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'The exit time of a completed monitoring record cannot be changed.';
    END IF;
END$$
DELIMITER ;

-- @DOWN
DROP TRIGGER IF EXISTS `trg_access_logs_entry_immutable`;
ALTER TABLE `vehicle_access_logs`
    DROP FOREIGN KEY `fk_access_logs_entry_operator_session`,
    DROP FOREIGN KEY `fk_access_logs_exit_operator_session`;
DROP TABLE IF EXISTS `access_denials`;
DROP TABLE IF EXISTS `vehicle_access_logs`;
