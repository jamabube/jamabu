<?php

declare(strict_types=1);

/**
 * Notification configuration.
 *
 * Each notification type declares its default priority, the roles that should
 * receive it, and the delivery channels enabled for it.
 */
return [
    'channels' => [
        'database' => true,
        'mail'     => (bool) env('MAIL_ENABLED', false),
    ],

    'priorities' => ['low', 'normal', 'high', 'critical'],

    'mail' => [
        'host'       => env('MAIL_HOST', ''),
        'port'       => (int) env('MAIL_PORT', 587),
        'username'   => env('MAIL_USERNAME', ''),
        'password'   => (string) env('MAIL_PASSWORD', ''),
        'encryption' => env('MAIL_ENCRYPTION', 'tls'),
        'from'       => [
            'address' => env('MAIL_FROM_ADDRESS', 'vams@forestlawn.local'),
            'name'    => env('MAIL_FROM_NAME', 'VAMS Notifications'),
        ],
        'timeout' => 15,
    ],

    /*
     * type => [priority, audience roles, channels]
     * An empty audience means "every user holding notifications.view".
     */
    'types' => [
        'vehicle.entered'        => ['priority' => 'low',      'roles' => ['administrator', 'supervisor', 'security'], 'channels' => ['database']],
        'vehicle.exited'         => ['priority' => 'low',      'roles' => ['administrator', 'supervisor', 'security'], 'channels' => ['database']],
        'vehicle.rejected'       => ['priority' => 'high',     'roles' => ['administrator', 'supervisor', 'security'], 'channels' => ['database']],
        'rfid.unknown'           => ['priority' => 'high',     'roles' => ['administrator', 'supervisor', 'security'], 'channels' => ['database']],
        'rfid.expired'           => ['priority' => 'normal',   'roles' => ['administrator', 'supervisor', 'security'], 'channels' => ['database']],
        'vehicle.inactive'       => ['priority' => 'normal',   'roles' => ['administrator', 'supervisor', 'security'], 'channels' => ['database']],
        'device.offline'         => ['priority' => 'critical', 'roles' => ['administrator', 'supervisor'],             'channels' => ['database', 'mail']],
        'device.online'          => ['priority' => 'normal',   'roles' => ['administrator', 'supervisor'],             'channels' => ['database']],
        'device.unknown'         => ['priority' => 'critical', 'roles' => ['administrator'],                           'channels' => ['database', 'mail']],
        'device.registered'      => ['priority' => 'normal',   'roles' => ['administrator'],                           'channels' => ['database']],
        'security.alert'         => ['priority' => 'critical', 'roles' => ['administrator'],                           'channels' => ['database', 'mail']],
        'security.flood'         => ['priority' => 'critical', 'roles' => ['administrator'],                           'channels' => ['database', 'mail']],
        'security.replay'        => ['priority' => 'critical', 'roles' => ['administrator'],                           'channels' => ['database', 'mail']],
        'security.lockout'       => ['priority' => 'high',     'roles' => ['administrator'],                           'channels' => ['database']],
        'fingerprint.failed'     => ['priority' => 'high',     'roles' => ['administrator', 'supervisor'],             'channels' => ['database']],
        'system.error'           => ['priority' => 'high',     'roles' => ['administrator'],                           'channels' => ['database']],
        'backup.completed'       => ['priority' => 'normal',   'roles' => ['administrator'],                           'channels' => ['database']],
        'backup.failed'          => ['priority' => 'critical', 'roles' => ['administrator'],                           'channels' => ['database', 'mail']],
        'user.created'           => ['priority' => 'normal',   'roles' => ['administrator'],                           'channels' => ['database']],
        'user.password_changed'  => ['priority' => 'normal',   'roles' => ['administrator'],                           'channels' => ['database']],
        'visitor.expired'        => ['priority' => 'normal',   'roles' => ['administrator', 'supervisor', 'security'], 'channels' => ['database']],
    ],

    'retention_days' => (int) env('RETENTION_NOTIFICATIONS', 365),
    'poll_interval'  => (int) env('NOTIFICATION_POLL_INTERVAL', 20),
];
