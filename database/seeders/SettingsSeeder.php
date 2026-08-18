<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database\Seeder;

/**
 * Seeds the runtime settings catalogue.
 *
 * Each row overrides the matching key in the configuration files once an
 * administrator edits it, which is what lets the organisation retune the
 * system without a deployment.
 *
 * @package Database\Seeders
 * @version 1.0.0
 */
final class SettingsSeeder extends Seeder
{
    public function description(): string
    {
        return 'Runtime system settings';
    }

    public function run(): void
    {
        foreach ($this->definitions() as $index => $definition) {
            [$key, $group, $label, $description, $value, $type, $options, $validation, $sensitive, $editable, $restart] = $definition;

            $this->upsert('system_settings', [
                'setting_key'      => $key,
                'setting_group'    => $group,
                'label'            => $label,
                'description'      => $description,
                'value'            => $value,
                'default_value'    => $value,
                'value_type'       => $type,
                'options'          => $options === null ? null : json_encode($options),
                'validation'       => $validation,
                'is_sensitive'     => $sensitive ? 1 : 0,
                'is_editable'      => $editable ? 1 : 0,
                'requires_restart' => $restart ? 1 : 0,
                'sort_order'       => ($index + 1) * 10,
            // The current value is never overwritten on a re-seed: an
            // administrator's tuning survives an upgrade.
            ], ['setting_key'], ['value']);
        }
    }

    /**
     * The settings catalogue.
     *
     * @return list<array{0:string,1:string,2:string,3:string,4:string,5:string,6:list<string>|null,7:string|null,8:bool,9:bool,10:bool}>
     */
    private function definitions(): array
    {
        return [
            // key, group, label, description, default, type, options, validation, sensitive, editable, restart
            ['app.name', 'general', 'System name', 'Displayed in the title bar, reports and exports.',
                (string) config('app.name', 'Vehicle Access Monitoring System'), 'string', null, 'required|string|max:100', false, true, false],
            ['app.organization', 'general', 'Organisation name', 'Printed on every report header.',
                (string) config('app.organization', 'Forest Lawn Memorial Park'), 'string', null, 'required|string|max:120', false, true, false],
            ['app.timezone', 'general', 'Timezone', 'Timezone applied to every displayed and recorded timestamp.',
                (string) config('app.timezone', 'Asia/Manila'), 'string', null, 'required|timezone', false, true, true],
            ['app.locale', 'general', 'Default language', 'Interface language for users who have not chosen one.',
                'en', 'select', ['en', 'fil'], 'required|in:en,fil', false, true, false],
            ['app.copyright', 'general', 'Copyright notice', 'Shown in the application footer.',
                (string) config('app.copyright', 'Forest Lawn Memorial Park'), 'string', null, 'nullable|max:120', false, true, false],
            ['app.support_contact', 'general', 'Administrator contact', 'Shown to users on an error page.',
                (string) config('app.support.administrator', 'ict@forestlawn.local'), 'string', null, 'nullable|email', false, true, false],
            ['ui.theme', 'appearance', 'Default theme', 'Applied to users who have not chosen a preference.',
                'light', 'select', ['light', 'dark', 'system'], 'required|in:light,dark,system', false, true, false],
            ['ui.records_per_page', 'appearance', 'Records per page', 'Default page size for every data table.',
                (string) config('app.pagination.default_per_page', 25), 'integer', null, 'required|integer|between:10,200', false, true, false],
            ['ui.dashboard_refresh', 'appearance', 'Dashboard refresh (seconds)', 'How often dashboard statistics reload.',
                (string) config('monitoring.live.dashboard_refresh', 15), 'integer', null, 'required|integer|between:5,300', false, true, false],
            ['ui.live_poll_interval', 'appearance', 'Live feed interval (seconds)', 'How often the live monitoring feed polls for new activity.',
                (string) config('monitoring.live.poll_interval_seconds', 5), 'integer', null, 'required|integer|between:2,60', false, true, false],

            ['security.session_timeout', 'security', 'Session idle timeout (seconds)', 'Inactivity after which a user is signed out.',
                (string) config('session.lifetime', 1800), 'integer', null, 'required|integer|between:300,28800', false, true, false],
            ['security.session_absolute', 'security', 'Absolute session lifetime (seconds)', 'Maximum age of a session regardless of activity.',
                (string) config('session.absolute_lifetime', 43200), 'integer', null, 'required|integer|between:1800,172800', false, true, false],
            ['security.single_session', 'security', 'One session per user', 'Terminate a previous session when a user signs in again.',
                config('session.concurrency.single_session', false) ? '1' : '0', 'boolean', null, 'boolean', false, true, false],
            ['security.max_login_attempts', 'security', 'Maximum login attempts', 'Consecutive failures before an account is locked.',
                (string) config('security.lockout.max_attempts', 5), 'integer', null, 'required|integer|between:3,20', false, true, false],
            ['security.lock_minutes', 'security', 'Lock duration (minutes)', 'How long a locked account stays locked.',
                (string) config('security.lockout.lock_minutes', 15), 'integer', null, 'required|integer|between:1,1440', false, true, false],
            ['security.password_min_length', 'security', 'Minimum password length', 'Shortest password the policy accepts.',
                (string) config('security.password.min_length', 12), 'integer', null, 'required|integer|between:8,128', false, true, false],
            ['security.password_max_age_days', 'security', 'Password maximum age (days)', 'Days before a password must be changed. 0 disables expiry.',
                (string) config('security.password.max_age_days', 90), 'integer', null, 'required|integer|between:0,3650', false, true, false],
            ['security.password_history', 'security', 'Password reuse depth', 'How many previous passwords may not be reused.',
                (string) config('security.password.history_depth', 5), 'integer', null, 'required|integer|between:0,24', false, true, false],
            ['security.force_https', 'security', 'Require HTTPS', 'Reject unencrypted requests.',
                config('security.transport.force_https', true) ? '1' : '0', 'boolean', null, 'boolean', false, true, true],

            ['api.rate_limit', 'api', 'API requests per window', 'Requests one identity may make per window.',
                (string) config('api.rate_limit.default.limit', 120), 'integer', null, 'required|integer|between:10,10000', false, true, false],
            ['api.rate_window', 'api', 'Rate limit window (seconds)', 'Length of the rate-limit window.',
                (string) config('api.rate_limit.default.window', 60), 'integer', null, 'required|integer|between:10,3600', false, true, false],
            ['api.timestamp_tolerance', 'api', 'Timestamp tolerance (seconds)', 'Clock skew allowed on a device request before it is rejected.',
                (string) config('api.device.timestamp_tolerance', 120), 'integer', null, 'required|integer|between:10,900', false, true, false],
            ['api.nonce_ttl', 'api', 'Replay window (seconds)', 'How long a nonce is remembered to defeat replay.',
                (string) config('api.device.nonce_ttl', 600), 'integer', null, 'required|integer|between:60,86400', false, true, false],
            ['api.require_signature', 'api', 'Require request signatures', 'Reject a device request that is not HMAC-signed.',
                config('api.device.require_signature', true) ? '1' : '0', 'boolean', null, 'boolean', false, true, false],
            ['api.flood_threshold', 'api', 'Flood threshold', 'Requests in a window that are treated as flooding.',
                (string) config('api.flood.threshold', 300), 'integer', null, 'required|integer|between:50,100000', false, true, false],

            ['device.heartbeat_interval', 'devices', 'Heartbeat interval (seconds)', 'How often a station is expected to report in.',
                (string) config('api.device.heartbeat_interval', 30), 'integer', null, 'required|integer|between:10,600', false, true, false],
            ['device.offline_after', 'devices', 'Offline threshold (seconds)', 'Silence after which a station is marked offline.',
                (string) config('api.device.offline_after', 90), 'integer', null, 'required|integer|between:30,3600', false, true, false],
            ['device.scan_debounce', 'devices', 'Scan debounce (seconds)', 'Repeat reads of one tag suppressed within this window.',
                (string) config('api.device.scan_debounce', 5), 'integer', null, 'required|integer|between:1,120', false, true, false],

            ['monitoring.require_operator', 'monitoring', 'Require operator authentication', 'A guard must be fingerprint-authenticated before a station records movements.',
                config('monitoring.rules.require_operator_authentication', true) ? '1' : '0', 'boolean', null, 'boolean', false, true, false],
            ['monitoring.operator_session_minutes', 'monitoring', 'Operator session length (minutes)', 'How long a station stays in monitoring mode before re-authentication.',
                (string) config('monitoring.rules.operator_session_minutes', 60), 'integer', null, 'required|integer|between:5,720', false, true, false],
            ['monitoring.minimum_stay', 'monitoring', 'Minimum stay (seconds)', 'An exit scanned sooner than this after entry is rejected as a double read.',
                (string) config('monitoring.rules.minimum_stay_seconds', 15), 'integer', null, 'required|integer|between:0,3600', false, true, false],
            ['monitoring.duplicate_window', 'monitoring', 'Duplicate scan window (seconds)', 'Repeat scans within this window are acknowledged without a second record.',
                (string) config('monitoring.rules.duplicate_scan_window_seconds', 10), 'integer', null, 'required|integer|between:0,300', false, true, false],
            ['monitoring.overstay_hours', 'monitoring', 'Overstay alert (hours)', 'Open entries older than this are flagged. 0 disables the check.',
                (string) config('monitoring.rules.overstay_alert_hours', 24), 'integer', null, 'required|integer|between:0,720', false, true, false],
            ['monitoring.visitor_validity_hours', 'monitoring', 'Default visitor pass validity (hours)', 'Applied when a visitor type does not define its own.',
                (string) config('monitoring.visitor.default_validity_hours', 12), 'integer', null, 'required|integer|between:1,720', false, true, false],

            ['backup.schedule', 'backup', 'Backup schedule', 'Cron expression used by the scheduled backup task.',
                (string) config('backup.schedule', '0 2 * * *'), 'string', null, 'required|cron', false, true, false],
            ['backup.retention', 'backup', 'Backups retained', 'How many backup archives to keep before pruning the oldest.',
                (string) config('backup.retention', 30), 'integer', null, 'required|integer|between:1,365', false, true, false],
            ['backup.compress', 'backup', 'Compress backups', 'Store backups as compressed archives.',
                config('backup.compress', true) ? '1' : '0', 'boolean', null, 'boolean', false, true, false],

            ['notifications.poll_interval', 'notifications', 'Notification poll interval (seconds)', 'How often the interface checks for new notifications.',
                (string) config('notifications.poll_interval', 20), 'integer', null, 'required|integer|between:5,300', false, true, false],
            ['notifications.mail_enabled', 'notifications', 'Send email notifications', 'Deliver critical notifications by email in addition to the inbox.',
                config('notifications.channels.mail', false) ? '1' : '0', 'boolean', null, 'boolean', false, true, false],
            ['notifications.mail_host', 'notifications', 'SMTP host', 'Mail server used for email notifications.',
                (string) config('notifications.mail.host', ''), 'string', null, 'nullable|max:120', false, true, false],
            ['notifications.mail_password', 'notifications', 'SMTP password', 'Credential for the mail server.',
                '', 'password', null, 'nullable|max:200', true, true, false],

            ['retention.api_logs_days', 'retention', 'API log retention (days)', 'Age at which API request logs may be pruned.',
                (string) config('logging.retention_days.api_request_logs', 90), 'integer', null, 'required|integer|between:7,3650', false, true, false],
            ['retention.heartbeat_days', 'retention', 'Heartbeat retention (days)', 'Age at which heartbeat telemetry may be pruned.',
                (string) config('logging.retention_days.device_heartbeats', 30), 'integer', null, 'required|integer|between:1,365', false, true, false],
            ['retention.notification_days', 'retention', 'Notification retention (days)', 'Age at which read notifications may be pruned.',
                (string) config('logging.retention_days.notifications', 365), 'integer', null, 'required|integer|between:30,3650', false, true, false],
            ['retention.audit_days', 'retention', 'Audit retention (days)', 'Age at which audit records may be archived. 0 retains indefinitely.',
                '0', 'integer', null, 'required|integer|between:0,3650', false, true, false],
            ['retention.security_days', 'retention', 'Security event retention (days)', 'Age at which security events may be archived. 0 retains indefinitely.',
                '0', 'integer', null, 'required|integer|between:0,3650', false, true, false],

            ['maintenance.enabled', 'maintenance', 'Maintenance mode', 'Bar everyone without the maintenance permission from the system.',
                '0', 'boolean', null, 'boolean', false, true, false],
            ['maintenance.message', 'maintenance', 'Maintenance message', 'Shown to users while maintenance mode is on.',
                'The system is undergoing scheduled maintenance. Please try again shortly.', 'text', null, 'nullable|max:500', false, true, false],
        ];
    }
}
