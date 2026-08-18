<?php

declare(strict_types=1);

/**
 * Vehicle access monitoring business rules.
 *
 * Every rule that governs whether a scan becomes an official monitoring record
 * is declared here rather than embedded in the service layer, so that the
 * organisation may adjust policy without a code change.
 */
return [
    /*
     * Access decision policy.
     */
    'rules' => [
        'require_operator_authentication' => (bool) env('MONITOR_REQUIRE_OPERATOR', true),
        'operator_session_minutes'        => (int) env('MONITOR_OPERATOR_SESSION', 60),
        'require_driver_assignment'       => (bool) env('MONITOR_REQUIRE_DRIVER', false),
        'allow_exit_without_entry'        => false,
        'allow_duplicate_entry'           => false,
        'reject_inactive_vehicle'         => true,
        'reject_expired_tag'              => true,
        'reject_inactive_tag'             => true,
        'reject_expired_visitor'          => true,
        /*
         * A scan of the same tag on the same device inside this window is
         * treated as a duplicate transmission and silently acknowledged
         * instead of creating a second record.
         */
        'duplicate_scan_window_seconds'   => (int) env('MONITOR_DUPLICATE_WINDOW', 10),
        /*
         * Minimum time a vehicle must remain inside before an exit scan is
         * accepted. Guards against a single pass triggering entry and exit.
         */
        'minimum_stay_seconds'            => (int) env('MONITOR_MIN_STAY', 15),
        /*
         * Entries still open after this many hours are flagged on the dashboard
         * as "overstaying" for administrative follow-up. 0 disables the check.
         */
        'overstay_alert_hours'            => (int) env('MONITOR_OVERSTAY_HOURS', 24),
    ],

    /*
     * Access types recognised by the monitoring engine.
     */
    'access_types' => ['entry', 'exit'],

    /*
     * Verification methods recorded against each transaction.
     */
    'verification_methods' => ['rfid', 'rfid+fingerprint', 'manual', 'visitor_card'],

    /*
     * Result codes stored in vehicle_access_logs.result and surfaced verbatim
     * in reports, so they must remain stable across releases.
     */
    'results' => [
        'granted'            => 'Access granted',
        'denied_unknown_tag' => 'RFID tag is not registered',
        'denied_inactive_tag'=> 'RFID tag is inactive',
        'denied_expired_tag' => 'RFID tag has expired',
        'denied_lost_tag'    => 'RFID tag reported lost',
        'denied_inactive_vehicle' => 'Vehicle is inactive',
        'denied_suspended_vehicle'=> 'Vehicle is suspended',
        'denied_duplicate_entry'  => 'Vehicle is already inside',
        'denied_no_active_entry'  => 'Vehicle has no open entry record',
        'denied_minimum_stay'     => 'Minimum stay duration not met',
        'denied_visitor_expired'  => 'Visitor access has expired',
        'denied_operator'         => 'No authenticated operator at the station',
        'denied_device'           => 'Device is not permitted to record this transaction',
        'denied_business_rule'    => 'Rejected by business rule',
    ],

    /*
     * Vehicle presence states derived from the access log.
     */
    'presence_states' => ['inside', 'outside', 'unknown'],

    'live' => [
        'poll_interval_seconds' => (int) env('MONITOR_POLL_INTERVAL', 5),
        'feed_size'             => (int) env('MONITOR_FEED_SIZE', 25),
        'dashboard_refresh'     => (int) env('DASHBOARD_REFRESH', 15),
    ],

    'visitor' => [
        'default_validity_hours' => (int) env('VISITOR_DEFAULT_VALIDITY_HOURS', 12),
        'auto_deactivate_expired'=> true,
        'require_authoriser'     => true,
    ],

    /*
     * Weighting used by DeviceHealthService when computing a device health
     * score. Weights must sum to 100.
     */
    'device_health_weights' => [
        'heartbeat_reliability' => 30,
        'communication_success' => 25,
        'signal_strength'       => 15,
        'restart_frequency'     => 10,
        'authentication_success'=> 10,
        'recent_errors'         => 10,
    ],

    'device_health_bands' => [
        'excellent' => 90,
        'good'      => 75,
        'warning'   => 50,
        'critical'  => 0,
    ],
];
