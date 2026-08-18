<?php

declare(strict_types=1);

/**
 * Database connection configuration.
 *
 * Only the backend application ever opens a connection; no client device or
 * browser is permitted to reach MySQL directly.
 */
return [
    'default' => env('DB_DRIVER', 'mysql'),

    'connections' => [
        'mysql' => [
            'driver'     => 'mysql',
            'host'       => env('DB_HOST', '127.0.0.1'),
            'port'       => (int) env('DB_PORT', 3306),
            'database'   => env('DB_DATABASE', 'vams'),
            'username'   => env('DB_USERNAME', 'vams_app'),
            'password'   => (string) env('DB_PASSWORD', ''),
            'charset'    => env('DB_CHARSET', 'utf8mb4'),
            'collation'  => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'socket'     => env('DB_SOCKET', ''),
            'persistent' => (bool) env('DB_PERSISTENT', false),
            'timezone'   => env('DB_TIMEZONE', '+00:00'),
            'strict'     => true,
            'engine'     => 'InnoDB',
        ],

        /*
         * SQLite connection used exclusively by the automated test harness so
         * that the suite can run without a MySQL server present.
         */
        'sqlite' => [
            'driver'   => 'sqlite',
            'database' => env('DB_SQLITE_PATH', ':memory:'),
        ],
    ],

    'migrations' => [
        'path'  => 'database/migrations',
        'table' => 'schema_migrations',
    ],

    'seeders' => [
        'path' => 'database/seeders',
    ],

    /*
     * Slow query threshold in milliseconds. Queries exceeding this budget are
     * written to the performance log for later optimisation.
     */
    'slow_query_ms' => (int) env('DB_SLOW_QUERY_MS', 500),
];
