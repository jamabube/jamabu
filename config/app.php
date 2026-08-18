<?php

declare(strict_types=1);

/**
 * Core application configuration.
 *
 * Every value is sourced from the environment so that no deployment-specific
 * detail is ever hardcoded into the source tree.
 */
return [
    'name'         => env('APP_NAME', 'Vehicle Access Monitoring System'),
    'short_name'   => env('APP_SHORT_NAME', 'VAMS'),
    'organization' => env('APP_ORGANIZATION', 'Forest Lawn Memorial Park'),
    'env'          => env('APP_ENV', 'production'),
    'debug'        => (bool) env('APP_DEBUG', false),
    'url'          => rtrim((string) env('APP_URL', 'http://localhost'), '/'),
    'timezone'     => env('APP_TIMEZONE', 'Asia/Manila'),
    'locale'       => env('APP_LOCALE', 'en'),
    'version'      => env('APP_VERSION', '1.0.0'),

    /*
     * Application key. Used for CSRF token derivation, signed payloads and
     * at-rest encryption of low-sensitivity configuration values.
     * Generate with: php bin/console key:generate
     */
    'key' => env('APP_KEY', ''),

    /*
     * Maintenance mode. When enabled only users holding the
     * "system.maintenance" permission may reach the application.
     */
    'maintenance' => [
        'enabled' => (bool) env('APP_MAINTENANCE', false),
        'message' => env('APP_MAINTENANCE_MESSAGE', 'The system is undergoing scheduled maintenance.'),
    ],

    'support' => [
        'administrator' => env('APP_ADMIN_CONTACT', 'ict@forestlawn.local'),
        'hotline'       => env('APP_SUPPORT_HOTLINE', ''),
    ],

    'pagination' => [
        'default_per_page' => (int) env('PAGINATION_PER_PAGE', 25),
        'max_per_page'     => (int) env('PAGINATION_MAX_PER_PAGE', 200),
        'options'          => [10, 25, 50, 100, 200],
    ],

    'copyright' => env('APP_COPYRIGHT', 'Forest Lawn Memorial Park'),
];
