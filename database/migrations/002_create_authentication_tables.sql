-- ===========================================================================
-- Migration 002 : Authentication, authorisation and session tables
--
-- Implements role-based access control. Permissions are never attached to a
-- user directly and never hardcoded in the application: a user holds a role,
-- a role holds permissions, and every protected operation checks the resulting
-- set. Adding a permission is a data change, not a code change.
-- ===========================================================================

-- @UP

-- ---------------------------------------------------------------------------
-- roles
-- ---------------------------------------------------------------------------
CREATE TABLE `roles` (
    `role_id`     INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_slug`   VARCHAR(40)  NOT NULL COMMENT 'Stable identifier used in code, e.g. "administrator"',
    `role_name`   VARCHAR(80)  NOT NULL,
    `description` VARCHAR(255)     NULL,
    `is_system`   TINYINT(1)   NOT NULL DEFAULT 0
        COMMENT 'System roles cannot be deleted; the last administrator cannot be demoted',
    `priority`    SMALLINT UNSIGNED NOT NULL DEFAULT 100
        COMMENT 'Lower value = higher authority; used to stop a user editing a role above their own',
    `status`      ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_by`  INT UNSIGNED     NULL,
    `updated_by`  INT UNSIGNED     NULL,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`  DATETIME         NULL,
    PRIMARY KEY (`role_id`),
    UNIQUE KEY `uq_roles_slug` (`role_slug`),
    UNIQUE KEY `uq_roles_name` (`role_name`),
    KEY `idx_roles_status` (`status`),
    KEY `idx_roles_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='System access levels; permissions are assigned separately';

-- ---------------------------------------------------------------------------
-- permissions
--
-- One row per discrete action the system can perform. The permission_key is
-- the value every authorisation check quotes, so it must never change once
-- released.
-- ---------------------------------------------------------------------------
CREATE TABLE `permissions` (
    `permission_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `module_id`       INT UNSIGNED     NULL,
    `module_key`      VARCHAR(50)  NOT NULL COMMENT 'Denormalised for grouping without a join in the permission matrix',
    `permission_key`  VARCHAR(80)  NOT NULL COMMENT 'e.g. vehicles.create',
    `permission_name` VARCHAR(120) NOT NULL,
    `description`     VARCHAR(255)     NULL,
    `is_dangerous`    TINYINT(1)   NOT NULL DEFAULT 0
        COMMENT 'Flags destructive capability (restore, delete, key rotation) for emphasis in the UI',
    `sort_order`      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `status`          ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`permission_id`),
    UNIQUE KEY `uq_permissions_key` (`permission_key`),
    KEY `idx_permissions_module` (`module_key`, `sort_order`),
    KEY `idx_permissions_status` (`status`),
    CONSTRAINT `fk_permissions_module`
        FOREIGN KEY (`module_id`) REFERENCES `system_modules` (`module_id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Every discrete action available in the system';

-- ---------------------------------------------------------------------------
-- role_permissions
--
-- Junction table resolving the many-to-many relationship. This table alone
-- determines authorisation throughout the application.
-- ---------------------------------------------------------------------------
CREATE TABLE `role_permissions` (
    `role_permission_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `role_id`            INT UNSIGNED NOT NULL,
    `permission_id`      INT UNSIGNED NOT NULL,
    `granted_by`         INT UNSIGNED     NULL,
    `granted_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`role_permission_id`),
    UNIQUE KEY `uq_role_permissions_pair` (`role_id`, `permission_id`),
    KEY `idx_role_permissions_permission` (`permission_id`),
    CONSTRAINT `fk_role_permissions_role`
        FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT `fk_role_permissions_permission`
        FOREIGN KEY (`permission_id`) REFERENCES `permissions` (`permission_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Grants: which permissions each role holds';

-- ---------------------------------------------------------------------------
-- users
--
-- full_name is a stored generated column so the displayed name can never drift
-- out of step with its parts, and so reports can index and sort on it.
-- ---------------------------------------------------------------------------
CREATE TABLE `users` (
    `user_id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `employee_number`       VARCHAR(30)      NULL,
    `first_name`            VARCHAR(60)  NOT NULL,
    `middle_name`           VARCHAR(60)      NULL,
    `last_name`             VARCHAR(60)  NOT NULL,
    `suffix`                VARCHAR(10)      NULL,
    `full_name`             VARCHAR(200)
        GENERATED ALWAYS AS (TRIM(CONCAT_WS(' ', `first_name`, `middle_name`, `last_name`, `suffix`))) STORED,
    `gender`                ENUM('male','female','other','undisclosed') NOT NULL DEFAULT 'undisclosed',
    `birth_date`            DATE             NULL,
    `username`              VARCHAR(50)  NOT NULL,
    `email`                 VARCHAR(150) NOT NULL,
    `mobile_number`         VARCHAR(30)      NULL,
    `password_hash`         VARCHAR(255) NOT NULL COMMENT 'bcrypt; the plain password is never stored or recoverable',
    `password_changed_at`   DATETIME         NULL,
    `must_change_password`  TINYINT(1)   NOT NULL DEFAULT 0,
    `role_id`               INT UNSIGNED NOT NULL,
    `department_id`         INT UNSIGNED     NULL,
    `position`              VARCHAR(80)      NULL,
    `profile_picture`       VARCHAR(120)     NULL COMMENT 'Stored filename only; the path comes from configuration',
    `status`                ENUM('active','inactive','suspended') NOT NULL DEFAULT 'active',
    `failed_login_attempts` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    `is_locked`             TINYINT(1)   NOT NULL DEFAULT 0,
    `locked_until`          DATETIME         NULL COMMENT 'NULL with is_locked = 1 means a permanent lock',
    `locked_reason`         VARCHAR(255)     NULL,
    `last_login_at`         DATETIME         NULL,
    `last_login_ip`         VARCHAR(45)      NULL,
    `last_login_user_agent` VARCHAR(255)     NULL,
    `last_activity_at`      DATETIME         NULL,
    `remarks`               TEXT             NULL,
    `created_by`            INT UNSIGNED     NULL,
    `updated_by`            INT UNSIGNED     NULL,
    `deleted_by`            INT UNSIGNED     NULL,
    `created_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`            DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `deleted_at`            DATETIME         NULL COMMENT 'Soft delete: historical audit records must keep resolving',
    PRIMARY KEY (`user_id`),
    UNIQUE KEY `uq_users_username` (`username`),
    UNIQUE KEY `uq_users_email` (`email`),
    UNIQUE KEY `uq_users_employee_number` (`employee_number`),
    KEY `idx_users_role` (`role_id`),
    KEY `idx_users_department` (`department_id`),
    KEY `idx_users_status` (`status`, `deleted_at`),
    KEY `idx_users_full_name` (`full_name`),
    KEY `idx_users_last_login` (`last_login_at`),
    CONSTRAINT `fk_users_role`
        FOREIGN KEY (`role_id`) REFERENCES `roles` (`role_id`)
        ON DELETE RESTRICT ON UPDATE CASCADE,
    CONSTRAINT `fk_users_department`
        FOREIGN KEY (`department_id`) REFERENCES `departments` (`department_id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Authenticated operators of the web application';

-- ---------------------------------------------------------------------------
-- password_history
--
-- Retains previous hashes so the reuse policy can be enforced. Only hashes are
-- kept, and they are pruned to the configured depth.
-- ---------------------------------------------------------------------------
CREATE TABLE `password_history` (
    `password_history_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`             INT UNSIGNED NOT NULL,
    `password_hash`       VARCHAR(255) NOT NULL,
    `changed_by`          INT UNSIGNED     NULL,
    `created_at`          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`password_history_id`),
    KEY `idx_password_history_user` (`user_id`, `created_at`),
    CONSTRAINT `fk_password_history_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Previous password hashes, for the reuse-restriction policy';

-- ---------------------------------------------------------------------------
-- password_resets
--
-- Reset tokens are stored as a hash, never in the clear: a leaked table must
-- not hand an attacker a working reset link.
-- ---------------------------------------------------------------------------
CREATE TABLE `password_resets` (
    `password_reset_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`           INT UNSIGNED NOT NULL,
    `token_hash`        CHAR(64)     NOT NULL COMMENT 'SHA-256 of the token handed to the user',
    `requested_by`      INT UNSIGNED     NULL COMMENT 'Administrator who initiated the reset, when applicable',
    `ip_address`        VARCHAR(45)      NULL,
    `expires_at`        DATETIME     NOT NULL,
    `used_at`           DATETIME         NULL,
    `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`password_reset_id`),
    UNIQUE KEY `uq_password_resets_token` (`token_hash`),
    KEY `idx_password_resets_user` (`user_id`, `expires_at`),
    CONSTRAINT `fk_password_resets_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Single-use password reset tokens';

-- ---------------------------------------------------------------------------
-- user_sessions
--
-- Server-side record of every active session, which is what makes "show active
-- sessions", "terminate a session" and concurrent-login control possible.
-- ---------------------------------------------------------------------------
CREATE TABLE `user_sessions` (
    `user_session_id`  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id`          INT UNSIGNED NOT NULL,
    `session_key`      CHAR(64)     NOT NULL COMMENT 'SHA-256 of the PHP session id; the raw id is never stored',
    `ip_address`       VARCHAR(45)      NULL,
    `user_agent`       VARCHAR(255)     NULL,
    `device_label`     VARCHAR(120)     NULL COMMENT 'Human summary derived from the user agent',
    `fingerprint`      CHAR(64)         NULL,
    `login_at`         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `last_activity_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `expires_at`       DATETIME         NULL,
    `logout_at`        DATETIME         NULL,
    `termination_reason` ENUM('logout','timeout','absolute_timeout','administrator','concurrent','fingerprint_mismatch','password_change') NULL,
    `terminated_by`    INT UNSIGNED     NULL,
    `status`           ENUM('active','ended') NOT NULL DEFAULT 'active',
    PRIMARY KEY (`user_session_id`),
    UNIQUE KEY `uq_user_sessions_key` (`session_key`),
    KEY `idx_user_sessions_user_status` (`user_id`, `status`),
    KEY `idx_user_sessions_activity` (`last_activity_at`),
    KEY `idx_user_sessions_login` (`login_at`),
    CONSTRAINT `fk_user_sessions_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Active and historical web sessions';

-- ---------------------------------------------------------------------------
-- login_attempts
--
-- Every attempt, successful or not, keyed by both username and source address
-- so brute force can be throttled per account and per origin. Retained as
-- evidence; never pruned automatically.
-- ---------------------------------------------------------------------------
CREATE TABLE `login_attempts` (
    `login_attempt_id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`         VARCHAR(50)  NOT NULL COMMENT 'As submitted, so attempts on non-existent accounts are still recorded',
    `user_id`          INT UNSIGNED     NULL,
    `ip_address`       VARCHAR(45)  NOT NULL,
    `user_agent`       VARCHAR(255)     NULL,
    `successful`       TINYINT(1)   NOT NULL DEFAULT 0,
    `failure_reason`   VARCHAR(60)      NULL COMMENT 'invalid_credentials, locked, inactive, ...',
    `attempted_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`login_attempt_id`),
    KEY `idx_login_attempts_username_time` (`username`, `attempted_at`),
    KEY `idx_login_attempts_ip_time` (`ip_address`, `attempted_at`),
    KEY `idx_login_attempts_user` (`user_id`, `attempted_at`),
    KEY `idx_login_attempts_success` (`successful`, `attempted_at`),
    CONSTRAINT `fk_login_attempts_user`
        FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
        ON DELETE SET NULL ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='Authentication attempt history driving lockout and detection';

-- Self-referencing audit columns are added after the table exists.
ALTER TABLE `users`
    ADD CONSTRAINT `fk_users_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_users_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_users_deleted_by` FOREIGN KEY (`deleted_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `roles`
    ADD CONSTRAINT `fk_roles_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE,
    ADD CONSTRAINT `fk_roles_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `role_permissions`
    ADD CONSTRAINT `fk_role_permissions_granted_by` FOREIGN KEY (`granted_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `password_history`
    ADD CONSTRAINT `fk_password_history_changed_by` FOREIGN KEY (`changed_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `password_resets`
    ADD CONSTRAINT `fk_password_resets_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `user_sessions`
    ADD CONSTRAINT `fk_user_sessions_terminated_by` FOREIGN KEY (`terminated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

ALTER TABLE `security_rules`
    ADD CONSTRAINT `fk_security_rules_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- @DOWN
-- Every foreign key pointing at `users` from a table this migration does not
-- itself drop must go first, otherwise the DROP is refused. The audit columns
-- on `roles` and `role_permissions` are exactly that case: they were added at
-- the end of the @UP section above, once `users` existed.
ALTER TABLE `security_rules`   DROP FOREIGN KEY `fk_security_rules_updated_by`;
ALTER TABLE `roles`            DROP FOREIGN KEY `fk_roles_created_by`;
ALTER TABLE `roles`            DROP FOREIGN KEY `fk_roles_updated_by`;
ALTER TABLE `role_permissions` DROP FOREIGN KEY `fk_role_permissions_granted_by`;
ALTER TABLE `users`            DROP FOREIGN KEY `fk_users_created_by`;
ALTER TABLE `users`            DROP FOREIGN KEY `fk_users_updated_by`;
ALTER TABLE `users`            DROP FOREIGN KEY `fk_users_deleted_by`;

-- Then the tables themselves, dependants before their parents.
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `user_sessions`;
DROP TABLE IF EXISTS `password_resets`;
DROP TABLE IF EXISTS `password_history`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `roles`;
