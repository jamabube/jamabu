<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database\Seeder;

/**
 * Seeds the reference vocabulary the rest of the system joins against.
 *
 * @package Database\Seeders
 * @version 1.0.0
 */
final class ReferenceSeeder extends Seeder
{
    public function description(): string
    {
        return 'Reference codes, modules, departments, vehicle and visitor types';
    }

    public function run(): void
    {
        $this->seedModules();
        $this->seedReferenceCodes();
        $this->seedDepartments();
        $this->seedVehicleTypes();
        $this->seedVisitorTypes();
        $this->seedNotificationTypes();
        $this->seedSecurityRules();
    }

    private function seedModules(): void
    {
        $modules = [
            ['dashboard',     'Dashboard',           'Operational overview and live statistics',        'fa-gauge-high',           10],
            ['monitoring',    'Vehicle Monitoring',  'Live monitoring and access history',              'fa-tower-broadcast',      20],
            ['vehicles',      'Vehicle Management',  'Registered vehicle records',                      'fa-car',                  30],
            ['owners',        'Owner Management',    'Registered vehicle owners',                       'fa-user-tie',             35],
            ['drivers',       'Driver Management',   'Authorised driver records',                       'fa-id-card',              40],
            ['visitors',      'Visitor Management',  'Temporary visitor passes',                        'fa-user-clock',           50],
            ['rfid',          'RFID Management',     'Windshield tags and visitor cards',               'fa-tags',                 60],
            ['fingerprints',  'Fingerprint Management', 'Biometric enrolment and verification',         'fa-fingerprint',          70],
            ['devices',       'Device Management',   'ESP32 monitoring stations',                       'fa-microchip',            80],
            ['reports',       'Reports',             'Report generation and analytics',                 'fa-file-lines',           90],
            ['notifications', 'Notifications',       'System notification centre',                      'fa-bell',                100],
            ['audit',         'Audit Trail',         'Immutable record of user actions',                'fa-clipboard-list',      110],
            ['security',      'Security Events',     'Detected suspicious activity',                    'fa-shield-halved',       120],
            ['errors',        'Error Logs',          'Application error diagnostics',                   'fa-triangle-exclamation',130],
            ['users',         'User Management',     'System user accounts',                            'fa-users',               140],
            ['roles',         'Role Management',     'Access levels',                                   'fa-user-shield',         150],
            ['permissions',   'Permission Management', 'Fine-grained capability assignment',            'fa-key',                 160],
            ['settings',      'System Settings',     'Runtime configuration',                           'fa-sliders',             170],
            ['backup',        'Backup & Restore',    'Database backup and recovery',                    'fa-database',            180],
            ['api',           'API Management',      'REST API keys and request history',               'fa-plug',                190],
            ['system',        'System',              'Health monitoring and maintenance',               'fa-heart-pulse',         200],
            ['profile',       'Profile',             'Personal account management',                     'fa-user',                210],
        ];

        foreach ($modules as [$key, $name, $description, $icon, $order]) {
            $this->upsert('system_modules', [
                'module_key'  => $key,
                'module_name' => $name,
                'description' => $description,
                'icon'        => $icon,
                'sort_order'  => $order,
                'status'      => 'active',
            ], ['module_key']);
        }
    }

    private function seedReferenceCodes(): void
    {
        /** @var list<array{0:string,1:string,2:string,3:string,4:string,5:int}> $codes */
        $codes = [
            // category, code, label, description, badge class, order
            ['access_type', 'entry', 'Entry', 'Vehicle entering the premises', 'success', 10],
            ['access_type', 'exit',  'Exit',  'Vehicle leaving the premises',  'primary', 20],

            ['presence', 'inside',  'Inside',  'Currently within the premises', 'success',   10],
            ['presence', 'outside', 'Outside', 'Not currently on the premises', 'secondary', 20],
            ['presence', 'unknown', 'Unknown', 'Presence could not be determined', 'warning', 30],

            ['verification_method', 'rfid',             'RFID only',          'Identified by windshield tag alone',       'info',    10],
            ['verification_method', 'rfid+fingerprint', 'RFID + fingerprint', 'Tag read with operator biometric approval','success', 20],
            ['verification_method', 'manual',           'Manual entry',       'Recorded by an operator without a tag read','warning',30],
            ['verification_method', 'visitor_card',     'Visitor card',       'Identified by a temporary visitor card',   'primary', 40],

            ['access_result', 'granted',                  'Access granted',              'The movement was recorded',                  'success', 10],
            ['access_result', 'denied_unknown_tag',       'Unknown tag',                 'The tag is not registered',                  'danger',  20],
            ['access_result', 'denied_inactive_tag',      'Inactive tag',                'The tag has been deactivated',               'warning', 30],
            ['access_result', 'denied_expired_tag',       'Expired tag',                 'The tag passed its expiration date',         'warning', 40],
            ['access_result', 'denied_lost_tag',          'Tag reported lost',           'The tag was reported lost or stolen',        'danger',  50],
            ['access_result', 'denied_inactive_vehicle',  'Inactive vehicle',            'The vehicle registration is not active',     'warning', 60],
            ['access_result', 'denied_suspended_vehicle', 'Suspended vehicle',           'The vehicle is suspended from entry',        'danger',  70],
            ['access_result', 'denied_duplicate_entry',   'Already inside',              'The vehicle has an open entry record',       'warning', 80],
            ['access_result', 'denied_no_active_entry',   'No open entry',               'An exit was scanned without a matching entry','warning',90],
            ['access_result', 'denied_minimum_stay',      'Minimum stay not met',        'The exit followed the entry too closely',    'secondary',100],
            ['access_result', 'denied_visitor_expired',   'Visitor pass expired',        'The temporary pass is no longer valid',      'warning', 110],
            ['access_result', 'denied_operator',          'No authenticated operator',   'No guard is authenticated at the station',   'danger',  120],
            ['access_result', 'denied_device',            'Station not permitted',       'This station may not record that movement',  'danger',  130],
            ['access_result', 'denied_business_rule',     'Rejected by policy',          'A configured business rule refused the scan','danger',  140],

            ['severity', 'low',      'Low',      'Informational',                   'secondary', 10],
            ['severity', 'medium',   'Medium',   'Warrants review',                 'info',      20],
            ['severity', 'high',     'High',     'Requires attention',              'warning',   30],
            ['severity', 'critical', 'Critical', 'Requires immediate investigation','danger',    40],

            ['device_connectivity', 'online',     'Online',     'Heartbeat received recently',           'success',   10],
            ['device_connectivity', 'offline',    'Offline',    'No heartbeat within the expected window','danger',   20],
            ['device_connectivity', 'never_seen', 'Never seen', 'The device has never reported in',      'secondary', 30],
            ['device_connectivity', 'disabled',   'Disabled',   'Administratively taken out of service', 'dark',      40],

            ['health_band', 'excellent', 'Excellent', 'Operating normally',              'success', 10],
            ['health_band', 'good',      'Good',      'Minor issues only',               'info',    20],
            ['health_band', 'warning',   'Warning',   'Degraded; schedule maintenance',  'warning', 30],
            ['health_band', 'critical',  'Critical',  'Requires immediate maintenance',  'danger',  40],
        ];

        foreach ($codes as [$category, $code, $label, $description, $badge, $order]) {
            $this->upsert('reference_codes', [
                'category'    => $category,
                'code'        => $code,
                'label'       => $label,
                'description' => $description,
                'badge_class' => $badge,
                'sort_order'  => $order,
                'is_system'   => 1,
                'status'      => 'active',
            ], ['category', 'code']);
        }
    }

    private function seedDepartments(): void
    {
        $departments = [
            ['ADM', 'Administration',      'Executive and administrative personnel'],
            ['SEC', 'Security',            'Guardhouse and patrol personnel'],
            ['ICT', 'Information Technology', 'System administration and maintenance'],
            ['OPS', 'Operations',          'Grounds and interment operations'],
            ['MNT', 'Maintenance',         'Facilities and equipment maintenance'],
        ];

        foreach ($departments as [$code, $name, $description]) {
            $this->upsert('departments', [
                'department_code' => $code,
                'department_name' => $name,
                'description'     => $description,
                'status'          => 'active',
            ], ['department_code']);
        }
    }

    private function seedVehicleTypes(): void
    {
        $types = [
            ['CAR',  'Private Car',      'Sedan, hatchback or coupe',              'fa-car',           10],
            ['SUV',  'SUV / Crossover',  'Sport utility and crossover vehicles',   'fa-car-side',      20],
            ['VAN',  'Van',              'Passenger and utility vans',             'fa-van-shuttle',   30],
            ['PICK', 'Pickup Truck',     'Light commercial pickups',               'fa-truck-pickup',  40],
            ['TRK',  'Truck',            'Heavy goods and service trucks',         'fa-truck',         50],
            ['MC',   'Motorcycle',       'Two and three wheeled vehicles',         'fa-motorcycle',    60],
            ['BUS',  'Bus / Coaster',    'Group transport vehicles',               'fa-bus',           70],
            ['HRS',  'Funeral Coach',    'Hearse and funeral service vehicles',    'fa-car-burst',     80],
            ['SVC',  'Service Vehicle',  'Park-owned operational vehicles',        'fa-tractor',       90],
        ];

        foreach ($types as [$code, $name, $description, $icon, $order]) {
            $this->upsert('vehicle_types', [
                'type_code'   => $code,
                'type_name'   => $name,
                'description' => $description,
                'icon'        => $icon,
                'sort_order'  => $order,
                'status'      => 'active',
            ], ['type_code']);
        }
    }

    private function seedVisitorTypes(): void
    {
        $types = [
            ['GUEST',  'Family / Guest',    'Relatives and guests visiting an interment site', 12, 0, 10],
            ['SUPP',   'Supplier',          'Delivering goods or materials',                    8, 1, 20],
            ['CONTR',  'Contractor',        'Performing contracted work on site',              10, 1, 30],
            ['OFFCL',  'Official',          'Government or regulatory representative',         24, 1, 40],
            ['SVCPRV', 'Service Provider',  'Funeral service and memorial providers',          12, 1, 50],
        ];

        foreach ($types as [$code, $name, $description, $hours, $requiresAuthoriser, $order]) {
            $this->upsert('visitor_types', [
                'type_code'              => $code,
                'type_name'              => $name,
                'description'            => $description,
                'default_validity_hours' => $hours,
                'requires_authoriser'    => $requiresAuthoriser,
                'sort_order'             => $order,
                'status'                 => 'active',
            ], ['type_code']);
        }
    }

    /**
     * Mirror the notification catalogue from configuration into the table so
     * an administrator can retune priority and audience at runtime.
     */
    private function seedNotificationTypes(): void
    {
        /** @var array<string,array{priority:string,roles:list<string>,channels:list<string>}> $types */
        $types = (array) config('notifications.types', []);

        foreach ($types as $key => $definition) {
            $this->upsert('notification_types', [
                'type_key'         => $key,
                'type_name'        => ucwords(str_replace(['.', '_'], ' ', (string) $key)),
                'description'      => sprintf('Raised when: %s', str_replace('.', ' ', (string) $key)),
                'default_priority' => (string) ($definition['priority'] ?? 'normal'),
                'audience_roles'   => implode(',', (array) ($definition['roles'] ?? [])),
                'channel_database' => in_array('database', (array) ($definition['channels'] ?? []), true) ? 1 : 0,
                'channel_mail'     => in_array('mail', (array) ($definition['channels'] ?? []), true) ? 1 : 0,
                'is_enabled'       => 1,
            ], ['type_key'], ['default_priority', 'audience_roles', 'channel_database', 'channel_mail', 'is_enabled']);
        }
    }

    private function seedSecurityRules(): void
    {
        $rules = [
            ['login.attempts',        'Failed login threshold',       'Consecutive failures before an account is locked',           (int) config('security.lockout.max_attempts', 5),        900, 'lock',   'high'],
            ['login.ip_attempts',     'Failed logins per address',    'Failures from one address before it is throttled',           (int) config('security.lockout.ip_max_attempts', 20),    900, 'block',  'high'],
            ['api.rate_limit',        'API request rate',             'Requests per identity within the window',                    (int) config('api.rate_limit.default.limit', 120),        60, 'block',  'medium'],
            ['api.flood',             'Request flood threshold',      'Requests within the window that indicate flooding',          (int) config('api.flood.threshold', 300),                 60, 'block',  'critical'],
            ['api.identical_payload', 'Identical payload repetition', 'Identical requests before the source is treated as flooding',(int) config('api.flood.identical_payload_threshold', 25),60, 'notify', 'high'],
            ['api.failures',          'Consecutive API failures',     'Rejected requests from one identity before it is blocked',   (int) config('api.flood.failure_threshold', 20),          60, 'block',  'high'],
            ['device.auth_failures',  'Device authentication failures','Failures before a device is suspended',                     (int) config('api.device.max_auth_failures', 10),        300, 'block',  'critical'],
            ['device.replay',         'Replay detection',             'A repeated nonce is always treated as a replay attempt',      1,                                                      600, 'block',  'critical'],
            ['rfid.unknown',          'Unknown tag scans',            'Unregistered tag reads before an alert is raised',            5,                                                      300, 'notify', 'high'],
            ['fingerprint.failures',  'Fingerprint verification failures','Failed verifications at one station before an alert',     5,                                                      300, 'notify', 'high'],
            ['session.fingerprint',   'Session fingerprint mismatch', 'A mismatch always terminates the session',                    1,                                                       60, 'block',  'critical'],
        ];

        foreach ($rules as [$key, $name, $description, $threshold, $window, $action, $severity]) {
            $this->upsert('security_rules', [
                'rule_key'        => $key,
                'rule_name'       => $name,
                'description'     => $description,
                'threshold_value' => $threshold,
                'window_seconds'  => $window,
                'action'          => $action,
                'severity'        => $severity,
                'is_enabled'      => 1,
            ], ['rule_key'], ['threshold_value', 'window_seconds', 'action', 'is_enabled']);
        }
    }
}
