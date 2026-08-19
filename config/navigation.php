<?php

declare(strict_types=1);

/**
 * Sidebar navigation definition.
 *
 * Menu entries are rendered only when the signed-in user holds the declared
 * permission, so a user never sees a module they cannot open. Keeping the
 * structure in configuration means new modules appear in the menu without any
 * change to the layout templates.
 *
 * Each item: label, route (named route), icon, permission, optional children.
 */
return [
    [
        'label' => 'Dashboard',
        'route' => 'dashboard',
        'icon'  => 'fa-gauge-high',
        'permission' => 'dashboard.view',
    ],
    [
        'header' => 'Monitoring',
    ],
    [
        'label' => 'Live Monitoring',
        'route' => 'monitoring.live',
        'icon'  => 'fa-tower-broadcast',
        'permission' => 'monitoring.view',
        'badge' => 'live',
    ],
    [
        'label' => 'Access History',
        'route' => 'monitoring.history',
        'icon'  => 'fa-clock-rotate-left',
        'permission' => 'monitoring.view',
    ],
    [
        'label' => 'Vehicles Inside',
        'route' => 'monitoring.inside',
        'icon'  => 'fa-warehouse',
        'permission' => 'monitoring.view',
    ],
    [
        'header' => 'Registry',
    ],
    [
        'label' => 'Vehicles',
        'route' => 'vehicles.index',
        'icon'  => 'fa-car',
        'permission' => 'vehicles.view',
    ],
    [
        'label' => 'Drivers',
        'route' => 'drivers.index',
        'icon'  => 'fa-id-card',
        'permission' => 'drivers.view',
    ],
    [
        'label' => 'Vehicle Owners',
        'route' => 'owners.index',
        'icon'  => 'fa-user-tie',
        'permission' => 'owners.view',
    ],
    [
        'label' => 'Visitors',
        'route' => 'visitors.index',
        'icon'  => 'fa-user-clock',
        'permission' => 'visitors.view',
    ],
    [
        'label' => 'RFID',
        'icon'  => 'fa-tags',
        'permission' => 'rfid.view',
        'children' => [
            ['label' => 'Windshield Tags', 'route' => 'rfid.tags.index',  'icon' => 'fa-tag',       'permission' => 'rfid.view'],
            ['label' => 'Visitor Cards',   'route' => 'rfid.cards.index', 'icon' => 'fa-credit-card','permission' => 'rfid.view'],
        ],
    ],
    [
        'label' => 'Fingerprints',
        'route' => 'fingerprints.index',
        'icon'  => 'fa-fingerprint',
        'permission' => 'fingerprints.view',
    ],
    [
        'header' => 'Infrastructure',
    ],
    [
        'label' => 'ESP32 Devices',
        'route' => 'devices.index',
        'icon'  => 'fa-microchip',
        'permission' => 'devices.view',
    ],
    [
        'label' => 'System Health',
        'route' => 'health.index',
        'icon'  => 'fa-heart-pulse',
        'permission' => 'system.health',
    ],
    [
        'header' => 'Insight',
    ],
    [
        'label' => 'Reports',
        'route' => 'reports.index',
        'icon'  => 'fa-file-lines',
        'permission' => 'reports.view',
    ],
    [
        'label' => 'Analytics',
        'route' => 'reports.analytics',
        'icon'  => 'fa-chart-line',
        'permission' => 'reports.view',
    ],
    [
        'label' => 'Notifications',
        'route' => 'notifications.index',
        'icon'  => 'fa-bell',
        'permission' => 'notifications.view',
        'badge' => 'notifications',
    ],
    [
        'header' => 'Governance',
    ],
    [
        'label' => 'Audit Logs',
        'route' => 'audit.index',
        'icon'  => 'fa-clipboard-list',
        'permission' => 'audit.view',
    ],
    [
        'label' => 'Security Events',
        'route' => 'security.index',
        'icon'  => 'fa-shield-halved',
        'permission' => 'security.view',
        'badge' => 'security',
    ],
    [
        'label' => 'Error Logs',
        'route' => 'errors.index',
        'icon'  => 'fa-triangle-exclamation',
        'permission' => 'errors.view',
    ],
    [
        'header' => 'Administration',
    ],
    [
        'label' => 'Users',
        'route' => 'users.index',
        'icon'  => 'fa-users',
        'permission' => 'users.view',
    ],
    [
        'label' => 'Roles',
        'route' => 'roles.index',
        'icon'  => 'fa-user-shield',
        'permission' => 'roles.view',
    ],
    [
        'label' => 'Permissions',
        'route' => 'permissions.index',
        'icon'  => 'fa-key',
        'permission' => 'permissions.view',
    ],
    [
        'label' => 'Departments',
        'route' => 'departments.index',
        'icon'  => 'fa-building',
        'permission' => 'users.view',
    ],
    [
        'label' => 'System Settings',
        'route' => 'settings.index',
        'icon'  => 'fa-sliders',
        'permission' => 'settings.view',
    ],
    [
        'label' => 'Backup & Restore',
        'route' => 'backups.index',
        'icon'  => 'fa-database',
        'permission' => 'backup.view',
    ],
    [
        'label' => 'API Management',
        'route' => 'api.manage',
        'icon'  => 'fa-plug',
        'permission' => 'api.manage',
    ],
];
