<?php

declare(strict_types=1);

/**
 * REST API and IoT device-communication configuration.
 */
return [
    'version'      => 'v1',
    'prefix'       => 'api',
    'supported_versions' => ['v1'],

    'device' => [
        'api_key_bytes'       => (int) env('API_KEY_BYTES', 32),
        'timestamp_tolerance' => (int) env('API_TIMESTAMP_TOLERANCE', 120),
        'nonce_ttl'           => (int) env('API_NONCE_TTL', 600),
        'require_signature'   => (bool) env('API_REQUIRE_SIGNATURE', true),
        'signature_algorithm' => 'sha256',
        'heartbeat_interval'  => (int) env('DEVICE_HEARTBEAT_INTERVAL', 30),
        'offline_after'       => (int) env('DEVICE_OFFLINE_AFTER', 90),
        'scan_debounce'       => (int) env('DEVICE_SCAN_DEBOUNCE', 5),
        'max_auth_failures'   => (int) env('DEVICE_MAX_AUTH_FAILURES', 10),
        'suspend_minutes'     => (int) env('DEVICE_SUSPEND_MINUTES', 30),
        'allowed_firmware'    => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('DEVICE_ALLOWED_FIRMWARE', ''))
        ))),
        'max_queue_replay_age' => (int) env('DEVICE_MAX_QUEUE_REPLAY_AGE', 86400),
    ],

    'headers' => [
        'device_id' => 'X-Device-Id',
        'api_key'   => 'X-Api-Key',
        'timestamp' => 'X-Timestamp',
        'nonce'     => 'X-Nonce',
        'signature' => 'X-Signature',
        'firmware'  => 'X-Firmware-Version',
        'request_id'=> 'X-Request-Id',
    ],

    'rate_limit' => [
        'enabled'  => (bool) env('API_RATE_LIMIT_ENABLED', true),
        'default'  => [
            'limit'  => (int) env('API_RATE_LIMIT', 120),
            'window' => (int) env('API_RATE_WINDOW', 60),
        ],
        /*
         * Per-route overrides keyed by rate-limit bucket name. Buckets are
         * declared on the route definition.
         */
        'buckets' => [
            'login'            => ['limit' => 10,  'window' => 60],
            'password-reset'   => ['limit' => 5,   'window' => 300],
            'device-auth'      => ['limit' => 30,  'window' => 60],
            'device-heartbeat' => ['limit' => 10,  'window' => 60],
            'access-scan'      => ['limit' => 60,  'window' => 60],
            'reports'          => ['limit' => 30,  'window' => 60],
            'export'           => ['limit' => 10,  'window' => 300],
            'backup'           => ['limit' => 5,   'window' => 600],
        ],
    ],

    'flood' => [
        'enabled'        => (bool) env('API_FLOOD_ENABLED', true),
        'threshold'      => (int) env('API_FLOOD_THRESHOLD', 300),
        'window'         => (int) env('API_FLOOD_WINDOW', 60),
        'block_minutes'  => (int) env('API_FLOOD_BLOCK_MINUTES', 15),
        'identical_payload_threshold' => (int) env('API_FLOOD_IDENTICAL_THRESHOLD', 25),
        'failure_threshold'           => (int) env('API_FLOOD_FAILURE_THRESHOLD', 20),
    ],

    'logging' => [
        'log_requests'      => (bool) env('API_LOG_REQUESTS', true),
        'log_request_body'  => (bool) env('API_LOG_REQUEST_BODY', false),
        'redact_fields'     => ['password', 'password_confirmation', 'api_key', 'signature', 'token', 'current_password', 'new_password'],
        'slow_request_ms'   => (int) env('API_SLOW_REQUEST_MS', 2000),
    ],
];
