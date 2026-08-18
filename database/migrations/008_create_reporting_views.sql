-- ===========================================================================
-- Migration 008 : Reporting views
--
-- The monitoring and reporting screens need the same eight-table join over and
-- over. Expressing it once as a view keeps that join in a single place, stops
-- the repositories from drifting apart, and lets MySQL merge it into the
-- caller's query so the indexes on the base tables are still used.
-- ===========================================================================

-- @UP

-- ---------------------------------------------------------------------------
-- v_access_monitoring
--
-- The canonical view behind live monitoring, access history, the vehicle
-- timeline and every movement report.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `v_access_monitoring` AS
SELECT
    l.`access_log_id`,
    l.`transaction_reference`,
    l.`access_type`,
    l.`status`,
    l.`result`,
    l.`is_visitor`,
    l.`scanned_uid`,
    l.`entry_time`,
    l.`exit_time`,
    l.`duration_seconds`,
    l.`entry_verification`,
    l.`exit_verification`,
    l.`remarks`,
    l.`annotation`,
    l.`created_at`,

    -- Registered vehicle, when the movement was not a visitor pass.
    l.`vehicle_id`,
    COALESCE(v.`plate_number`, l.`plate_number`) AS `plate_number`,
    v.`vehicle_code`,
    v.`brand`,
    v.`model`,
    v.`colour`,
    v.`status`        AS `vehicle_status`,
    vt.`type_name`    AS `vehicle_type`,

    -- Owner and driver.
    o.`owner_id`,
    o.`full_name`     AS `owner_name`,
    o.`contact_number` AS `owner_contact`,
    l.`driver_id`,
    d.`full_name`     AS `driver_name`,
    d.`contact_number` AS `driver_contact`,

    -- Credential presented.
    l.`rfid_tag_id`,
    t.`tag_code`,
    l.`rfid_card_id`,
    c.`card_code`,

    -- Visitor pass, when applicable.
    l.`visitor_log_id`,
    vl.`pass_reference`,
    vl.`purpose`      AS `visit_purpose`,
    vis.`full_name`   AS `visitor_name`,
    vis.`company`     AS `visitor_company`,

    -- Stations and accountable operators.
    l.`entry_device_id`,
    ed.`device_name`  AS `entry_device_name`,
    ed.`location`     AS `entry_location`,
    l.`exit_device_id`,
    xd.`device_name`  AS `exit_device_name`,
    xd.`location`     AS `exit_location`,
    l.`entry_operator_id`,
    eo.`full_name`    AS `entry_operator_name`,
    l.`exit_operator_id`,
    xo.`full_name`    AS `exit_operator_name`
FROM `vehicle_access_logs` l
LEFT JOIN `vehicles`        v   ON v.`vehicle_id`      = l.`vehicle_id`
LEFT JOIN `vehicle_types`   vt  ON vt.`vehicle_type_id` = v.`vehicle_type_id`
LEFT JOIN `vehicle_owners`  o   ON o.`owner_id`        = v.`owner_id`
LEFT JOIN `drivers`         d   ON d.`driver_id`       = l.`driver_id`
LEFT JOIN `rfid_tags`       t   ON t.`rfid_tag_id`     = l.`rfid_tag_id`
LEFT JOIN `rfid_cards`      c   ON c.`rfid_card_id`    = l.`rfid_card_id`
LEFT JOIN `visitor_logs`    vl  ON vl.`visitor_log_id` = l.`visitor_log_id`
LEFT JOIN `visitors`        vis ON vis.`visitor_id`    = vl.`visitor_id`
LEFT JOIN `devices`         ed  ON ed.`device_id`      = l.`entry_device_id`
LEFT JOIN `devices`         xd  ON xd.`device_id`      = l.`exit_device_id`
LEFT JOIN `users`           eo  ON eo.`user_id`        = l.`entry_operator_id`
LEFT JOIN `users`           xo  ON xo.`user_id`        = l.`exit_operator_id`;

-- ---------------------------------------------------------------------------
-- v_vehicle_directory
--
-- A registered vehicle with everything the listing, the detail page and the
-- exports display, plus its current presence state.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `v_vehicle_directory` AS
SELECT
    v.`vehicle_id`,
    v.`vehicle_code`,
    v.`plate_number`,
    v.`brand`,
    v.`model`,
    v.`colour`,
    v.`year_model`,
    v.`status`,
    v.`registration_date`,
    v.`insurance_expiry`,
    v.`photo`,
    v.`remarks`,
    v.`created_at`,
    v.`updated_at`,
    v.`deleted_at`,
    vt.`vehicle_type_id`,
    vt.`type_name`      AS `vehicle_type`,
    o.`owner_id`,
    o.`full_name`       AS `owner_name`,
    o.`contact_number`  AS `owner_contact`,
    o.`owner_category`,
    d.`driver_id`,
    d.`full_name`       AS `driver_name`,
    d.`contact_number`  AS `driver_contact`,
    t.`rfid_tag_id`,
    t.`rfid_uid`,
    t.`tag_code`,
    t.`status`          AS `tag_status`,
    t.`expiration_date` AS `tag_expiration`,
    t.`last_scanned_at`,
    -- Presence is derived from the access log rather than stored on the
    -- vehicle, so it cannot drift out of step with the movement history.
    CASE WHEN open_visit.`access_log_id` IS NULL THEN 'outside' ELSE 'inside' END AS `presence`,
    open_visit.`access_log_id` AS `open_access_log_id`,
    open_visit.`entry_time`    AS `current_entry_time`
FROM `vehicles` v
LEFT JOIN `vehicle_types`  vt ON vt.`vehicle_type_id` = v.`vehicle_type_id`
LEFT JOIN `vehicle_owners` o  ON o.`owner_id`         = v.`owner_id`
LEFT JOIN `drivers`        d  ON d.`driver_id`        = v.`driver_id`
LEFT JOIN `rfid_tags`      t  ON t.`rfid_tag_id`      = v.`rfid_tag_id`
LEFT JOIN `vehicle_access_logs` open_visit
       ON open_visit.`vehicle_id` = v.`vehicle_id`
      AND open_visit.`status`     = 'inside';

-- ---------------------------------------------------------------------------
-- v_device_status
--
-- Device rows with the online/offline decision applied once, centrally, using
-- each device's own configured heartbeat interval.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `v_device_status` AS
SELECT
    dv.`device_id`,
    dv.`device_code`,
    dv.`device_name`,
    dv.`location`,
    dv.`gate_type`,
    dv.`gate_label`,
    dv.`ip_address`,
    dv.`mac_address`,
    dv.`firmware_version`,
    dv.`signal_strength`,
    dv.`heartbeat_interval`,
    dv.`last_heartbeat_at`,
    dv.`last_communication_at`,
    dv.`last_scan_at`,
    dv.`restart_count`,
    dv.`communication_count`,
    dv.`error_count`,
    dv.`auth_failure_count`,
    dv.`health_score`,
    dv.`status`,
    dv.`installation_date`,
    dv.`created_at`,
    dv.`deleted_at`,
    TIMESTAMPDIFF(SECOND, dv.`last_heartbeat_at`, NOW()) AS `seconds_since_heartbeat`,
    CASE
        WHEN dv.`status` <> 'active'                        THEN 'disabled'
        WHEN dv.`last_heartbeat_at` IS NULL                 THEN 'never_seen'
        -- Three missed intervals is the offline threshold: one missed beat is
        -- a hiccup, three is an outage.
        WHEN TIMESTAMPDIFF(SECOND, dv.`last_heartbeat_at`, NOW()) > (dv.`heartbeat_interval` * 3) THEN 'offline'
        ELSE 'online'
    END AS `connectivity`
FROM `devices` dv;

-- ---------------------------------------------------------------------------
-- v_daily_access_summary
--
-- Pre-aggregated per-day movement counts backing the dashboard charts and the
-- daily/weekly/monthly reports.
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `v_daily_access_summary` AS
SELECT
    DATE(l.`entry_time`)                     AS `activity_date`,
    COUNT(*)                                 AS `total_entries`,
    SUM(CASE WHEN l.`exit_time` IS NOT NULL THEN 1 ELSE 0 END) AS `total_exits`,
    SUM(CASE WHEN l.`status` = 'inside' THEN 1 ELSE 0 END)     AS `still_inside`,
    SUM(CASE WHEN l.`is_visitor` = 1 THEN 1 ELSE 0 END)        AS `visitor_entries`,
    COUNT(DISTINCT l.`vehicle_id`)           AS `unique_vehicles`,
    COUNT(DISTINCT l.`entry_device_id`)      AS `devices_used`,
    COUNT(DISTINCT l.`entry_operator_id`)    AS `operators_on_duty`,
    ROUND(AVG(l.`duration_seconds`))         AS `average_stay_seconds`,
    MAX(l.`duration_seconds`)                AS `longest_stay_seconds`
FROM `vehicle_access_logs` l
GROUP BY DATE(l.`entry_time`);

-- ---------------------------------------------------------------------------
-- v_user_directory
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `v_user_directory` AS
SELECT
    u.`user_id`,
    u.`employee_number`,
    u.`username`,
    u.`full_name`,
    u.`first_name`,
    u.`last_name`,
    u.`email`,
    u.`mobile_number`,
    u.`position`,
    u.`status`,
    u.`is_locked`,
    u.`locked_until`,
    u.`failed_login_attempts`,
    u.`last_login_at`,
    u.`last_login_ip`,
    u.`password_changed_at`,
    u.`must_change_password`,
    u.`profile_picture`,
    u.`created_at`,
    u.`deleted_at`,
    r.`role_id`,
    r.`role_name`,
    r.`role_slug`,
    r.`priority`      AS `role_priority`,
    dp.`department_id`,
    dp.`department_name`,
    fp.`template_id`  AS `fingerprint_template_id`,
    fp.`template_number` AS `fingerprint_number`,
    fp.`status`       AS `fingerprint_status`,
    (SELECT COUNT(*) FROM `user_sessions` s
      WHERE s.`user_id` = u.`user_id` AND s.`status` = 'active') AS `active_sessions`
FROM `users` u
INNER JOIN `roles`       r  ON r.`role_id`       = u.`role_id`
LEFT  JOIN `departments` dp ON dp.`department_id` = u.`department_id`
LEFT  JOIN `fingerprint_templates` fp ON fp.`template_id` = u.`fingerprint_template_id`;

-- ---------------------------------------------------------------------------
-- v_visitor_activity
-- ---------------------------------------------------------------------------
CREATE OR REPLACE VIEW `v_visitor_activity` AS
SELECT
    vl.`visitor_log_id`,
    vl.`pass_reference`,
    vl.`purpose`,
    vl.`destination`,
    vl.`vehicle_plate`,
    vl.`vehicle_description`,
    vl.`companions`,
    vl.`issued_at`,
    vl.`valid_from`,
    vl.`valid_until`,
    vl.`entry_time`,
    vl.`exit_time`,
    vl.`status`,
    vl.`remarks`,
    TIMESTAMPDIFF(SECOND, vl.`entry_time`, vl.`exit_time`) AS `duration_seconds`,
    CASE WHEN vl.`valid_until` < NOW() AND vl.`status` IN ('issued','inside') THEN 1 ELSE 0 END AS `is_overdue`,
    v.`visitor_id`,
    v.`visitor_code`,
    v.`full_name`      AS `visitor_name`,
    v.`company`,
    v.`contact_number`,
    v.`is_blacklisted`,
    vt.`type_name`     AS `visitor_type`,
    c.`rfid_card_id`,
    c.`card_code`,
    c.`card_uid`,
    a.`full_name`      AS `authorised_by_name`,
    i.`full_name`      AS `issued_by_name`
FROM `visitor_logs` vl
INNER JOIN `visitors`      v  ON v.`visitor_id`      = vl.`visitor_id`
LEFT  JOIN `visitor_types` vt ON vt.`visitor_type_id` = v.`visitor_type_id`
LEFT  JOIN `rfid_cards`    c  ON c.`rfid_card_id`    = vl.`rfid_card_id`
LEFT  JOIN `users`         a  ON a.`user_id`         = vl.`authorized_by`
LEFT  JOIN `users`         i  ON i.`user_id`         = vl.`issued_by`;

-- @DOWN
DROP VIEW IF EXISTS `v_visitor_activity`;
DROP VIEW IF EXISTS `v_user_directory`;
DROP VIEW IF EXISTS `v_daily_access_summary`;
DROP VIEW IF EXISTS `v_device_status`;
DROP VIEW IF EXISTS `v_vehicle_directory`;
DROP VIEW IF EXISTS `v_access_monitoring`;
