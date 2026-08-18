<?php

declare(strict_types=1);

/**
 * Session configuration.
 *
 * Sessions are stored server-side; the identifier is transported in a
 * hardened, HTTP-only cookie and never appears in a URL.
 */
return [
    'name'               => env('SESSION_NAME', 'VAMSSESSID'),
    'lifetime'           => (int) env('SESSION_LIFETIME', 1800),
    'absolute_lifetime'  => (int) env('SESSION_ABSOLUTE_LIFETIME', 43200),
    'regenerate_interval'=> (int) env('SESSION_REGENERATE_INTERVAL', 300),
    'save_path'          => env('SESSION_SAVE_PATH', ''),

    'cookie' => [
        'secure'    => (bool) env('SESSION_SECURE_COOKIE', true),
        'http_only' => true,
        'same_site' => env('SESSION_SAME_SITE', 'Lax'),
        'path'      => env('SESSION_COOKIE_PATH', '/'),
        'domain'    => env('SESSION_COOKIE_DOMAIN', ''),
    ],

    /*
     * Session-hijacking countermeasures. The fingerprint binds a session to the
     * originating user agent and (optionally) IP address.
     */
    'fingerprint' => [
        'bind_user_agent' => true,
        'bind_ip'         => (bool) env('SESSION_BIND_IP', false),
    ],

    'concurrency' => [
        'single_session'          => (bool) env('SESSION_SINGLE', false),
        'terminate_previous'      => (bool) env('SESSION_TERMINATE_PREVIOUS', true),
        'notify_administrator'    => true,
        'max_concurrent_sessions' => (int) env('SESSION_MAX_CONCURRENT', 3),
    ],

    'idle_warning_seconds' => (int) env('SESSION_IDLE_WARNING', 120),
];
