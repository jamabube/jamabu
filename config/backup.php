<?php

declare(strict_types=1);

/**
 * Database backup and recovery configuration.
 */
return [
    'path'      => env('BACKUP_PATH', 'database/backups'),
    'retention' => (int) env('BACKUP_RETENTION', 30),
    'schedule'  => env('BACKUP_SCHEDULE', '0 2 * * *'),

    'binaries' => [
        'mysqldump' => env('MYSQLDUMP_PATH', 'mysqldump'),
        'mysql'     => env('MYSQL_PATH', 'mysql'),
    ],

    'compress'          => (bool) env('BACKUP_COMPRESS', true),
    'checksum_algorithm'=> 'sha256',
    'verify_after_backup' => true,

    /*
     * Contents included in a "full" backup.
     */
    'include' => [
        'database'      => true,
        'configuration' => true,
        'uploads'       => (bool) env('BACKUP_INCLUDE_UPLOADS', true),
        'logs'          => (bool) env('BACKUP_INCLUDE_LOGS', false),
        'reports'       => false,
    ],

    'restore' => [
        'require_confirmation'   => true,
        'snapshot_before_restore'=> true,
        'verify_checksum'        => true,
    ],

    'max_backup_bytes' => (int) env('BACKUP_MAX_BYTES', 2147483648),
];
