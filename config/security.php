<?php

declare(strict_types=1);

/**
 * Security configuration.
 *
 * These values drive the password policy, account lockout behaviour, CSRF
 * protection, HTTP security headers and transport enforcement. Administrators
 * may override most of them at runtime through the System Settings module;
 * the values below act as the bootstrap defaults.
 */
return [
    'password' => [
        'algorithm'          => PASSWORD_BCRYPT,
        'bcrypt_cost'        => (int) env('BCRYPT_COST', 12),
        'min_length'         => (int) env('PASSWORD_MIN_LENGTH', 12),
        'max_length'         => 128,
        'require_uppercase'  => true,
        'require_lowercase'  => true,
        'require_numeric'    => true,
        'require_special'    => true,
        'max_age_days'       => (int) env('PASSWORD_MAX_AGE_DAYS', 90),
        'history_depth'      => (int) env('PASSWORD_HISTORY', 5),
        'reject_similar_to_username' => true,
        'similarity_threshold'       => 0.7,
        'dictionary_file'    => 'config/weak-passwords.txt',
        'reset_token_ttl'    => (int) env('PASSWORD_RESET_TTL', 3600),
        'temporary_length'   => 16,
    ],

    'lockout' => [
        'max_attempts'      => (int) env('LOGIN_MAX_ATTEMPTS', 5),
        'lock_minutes'      => (int) env('LOGIN_LOCK_MINUTES', 15),
        'permanent_after'   => (int) env('LOGIN_PERMANENT_LOCK_AFTER', 0), // 0 = never
        'notify_administrator' => true,
        'track_by_ip'       => true,
        'ip_max_attempts'   => (int) env('LOGIN_IP_MAX_ATTEMPTS', 20),
        'ip_window_minutes' => (int) env('LOGIN_IP_WINDOW_MINUTES', 15),
    ],

    'csrf' => [
        'enabled'       => true,
        'token_name'    => '_csrf_token',
        'header_name'   => 'X-CSRF-Token',
        'lifetime'      => (int) env('CSRF_TOKEN_LIFETIME', 7200),
        'pool_size'     => 12, // number of concurrently valid tokens per session
        'safe_methods'  => ['GET', 'HEAD', 'OPTIONS'],
    ],

    'transport' => [
        'force_https' => (bool) env('FORCE_HTTPS', true),
        'hsts' => [
            'enabled'            => (bool) env('HSTS_ENABLED', true),
            'max_age'            => (int) env('HSTS_MAX_AGE', 31536000),
            'include_subdomains' => true,
            'preload'            => false,
        ],
    ],

    /*
     * HTTP response headers applied to every response by SecurityHeadersMiddleware.
     * A null value removes the header.
     */
    'headers' => [
        'X-Frame-Options'        => 'DENY',
        'X-Content-Type-Options' => 'nosniff',
        'Referrer-Policy'        => 'same-origin',
        'Permissions-Policy'     => 'geolocation=(), microphone=(), camera=(), payment=(), usb=()',
        'Cross-Origin-Opener-Policy'   => 'same-origin',
        'Cross-Origin-Resource-Policy' => 'same-origin',
        'X-Permitted-Cross-Domain-Policies' => 'none',
    ],

    /*
     * Content Security Policy. Assembled into a header string by the
     * SecurityHeadersMiddleware. Per-request nonces are injected for inline
     * scripts, so 'unsafe-inline' is never required.
     */
    'csp' => [
        'enabled'  => true,
        'report_only' => (bool) env('CSP_REPORT_ONLY', false),
        'directives' => [
            'default-src'     => ["'self'"],
            'base-uri'        => ["'self'"],
            'object-src'      => ["'none'"],
            'frame-ancestors' => ["'none'"],
            'form-action'     => ["'self'"],
            'img-src'         => ["'self'", 'data:'],
            'font-src'        => ["'self'", 'data:'],
            'style-src'       => ["'self'", "'unsafe-inline'"],
            'script-src'      => ["'self'"],
            'connect-src'     => ["'self'"],
            'manifest-src'    => ["'self'"],
        ],
    ],

    /*
     * Input sanitisation performed before any request reaches a controller.
     */
    'sanitisation' => [
        'trim_strings'         => true,
        'convert_empty_to_null' => true,
        'strip_control_chars'  => true,
        'reject_invalid_utf8'  => true,
        'max_input_depth'      => 12,
        'max_field_length'     => 65535,
        'max_body_bytes'       => (int) env('MAX_BODY_BYTES', 2097152),
    ],

    'uploads' => [
        'max_bytes'       => (int) env('UPLOAD_MAX_BYTES', 2097152),
        'allowed_images'  => ['image/jpeg', 'image/png', 'image/webp'],
        'allowed_documents' => ['application/pdf'],
        'path'            => 'public/uploads',
        'randomise_names' => true,
    ],

    /*
     * Optional network allow-list. When non-empty, only these CIDR ranges may
     * reach the administrative interface. LAN deployments typically list the
     * guardhouse and administration subnets.
     */
    'ip_allowlist' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('IP_ALLOWLIST', ''))
    ))),

    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TRUSTED_PROXIES', ''))
    ))),
];
