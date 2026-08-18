<?php

declare(strict_types=1);

/**
 * Centralised logging configuration.
 *
 * Every channel may write to a rotating file, to the database, or to both.
 * Audit records, security events and error logs are additionally persisted in
 * MySQL so that they are queryable from the administrative interface.
 */
return [
    'level' => env('LOG_LEVEL', 'info'),

    'to_file'     => (bool) env('LOG_TO_FILE', true),
    'to_database' => (bool) env('LOG_TO_DATABASE', true),

    'channels' => [
        'application' => ['path' => 'storage/logs/system',   'database' => false],
        'audit'       => ['path' => 'storage/logs/audit',    'database' => true],
        'security'    => ['path' => 'storage/logs/security', 'database' => true],
        'error'       => ['path' => 'storage/logs/errors',   'database' => true],
        'api'         => ['path' => 'storage/logs/api',      'database' => true],
        'device'      => ['path' => 'storage/logs/system',   'database' => false],
        'performance' => ['path' => 'storage/logs/system',   'database' => false],
    ],

    'rotation' => [
        'daily'          => true,
        'max_file_bytes' => (int) env('LOG_MAX_FILE_BYTES', 10485760),
        'max_files'      => (int) env('LOG_MAX_FILES', 60),
    ],

    /*
     * Retention in days per category. A value of 0 means "retain indefinitely".
     * Retention is applied by the log:prune console command, never
     * automatically, so that no evidence disappears without an administrator
     * explicitly scheduling it.
     */
    'retention_days' => [
        'audit_logs'       => (int) env('RETENTION_AUDIT', 0),
        'security_events'  => (int) env('RETENTION_SECURITY', 0),
        'error_logs'       => (int) env('RETENTION_ERRORS', 730),
        'api_request_logs' => (int) env('RETENTION_API', 90),
        'device_heartbeats'=> (int) env('RETENTION_HEARTBEATS', 30),
        'notifications'    => (int) env('RETENTION_NOTIFICATIONS', 365),
        'files'            => (int) env('LOG_RETENTION_DAYS', 365),
    ],

    /*
     * Keys whose values are masked before a payload is written to any log.
     */
    'redact' => [
        'password', 'password_confirmation', 'current_password', 'new_password',
        'api_key', 'signature', 'token', 'secret', 'authorization', 'cookie',
        'fingerprint_template', 'db_password',
    ],
];
