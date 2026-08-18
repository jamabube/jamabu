<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Core\Database\Seeder;

/**
 * Seeds roles, the permission catalogue and the grants between them.
 *
 * The permission list here is the authoritative catalogue: every route and
 * every controller check quotes one of these keys. Adding a capability to the
 * system means adding a row here, never editing an authorisation check.
 *
 * @package Database\Seeders
 * @version 1.0.0
 */
final class RbacSeeder extends Seeder
{
    /**
     * The full permission catalogue.
     *
     * module => [key => [name, description, dangerous]]
     *
     * @var array<string,array<string,array{0:string,1:string,2:bool}>>
     */
    private const PERMISSIONS = [
        'system' => [
            '*'                  => ['Full system access', 'Unrestricted access to every module and action', true],
            'system.health'      => ['View system health', 'Read the health dashboard and diagnostics', false],
            'system.maintenance' => ['Maintenance mode', 'Enable maintenance mode and reach the system while it is on', true],
        ],
        'dashboard' => [
            'dashboard.view'    => ['View dashboard', 'Open the operational dashboard', false],
            'dashboard.refresh' => ['Refresh dashboard data', 'Poll live dashboard statistics', false],
        ],
        'monitoring' => [
            'monitoring.view'        => ['View monitoring', 'Live monitoring, access history and vehicle timelines', false],
            'monitoring.annotate'    => ['Annotate records', 'Add an administrative note to a monitoring record', false],
            'monitoring.force_close' => ['Force-close a visit', 'Close an entry that never received a matching exit', true],
            'monitoring.manual'      => ['Record manually', 'Record a movement without a tag read', true],
            'monitoring.export'      => ['Export monitoring data', 'Download monitoring records', false],
        ],
        'vehicles' => [
            'vehicles.view'    => ['View vehicles', 'Browse the vehicle registry', false],
            'vehicles.create'  => ['Register a vehicle', 'Add a new vehicle to the registry', false],
            'vehicles.update'  => ['Edit a vehicle', 'Modify vehicle details', false],
            'vehicles.delete'  => ['Deactivate a vehicle', 'Soft-delete a vehicle record', true],
            'vehicles.restore' => ['Restore a vehicle', 'Reverse a vehicle deactivation', true],
            'vehicles.export'  => ['Export vehicles', 'Download the vehicle registry', false],
        ],
        'owners' => [
            'owners.view'   => ['View owners', 'Browse registered vehicle owners', false],
            'owners.create' => ['Register an owner', 'Add a new vehicle owner', false],
            'owners.update' => ['Edit an owner', 'Modify owner details', false],
            'owners.delete' => ['Deactivate an owner', 'Soft-delete an owner record', true],
        ],
        'drivers' => [
            'drivers.view'   => ['View drivers', 'Browse the driver registry', false],
            'drivers.create' => ['Register a driver', 'Add a new authorised driver', false],
            'drivers.update' => ['Edit a driver', 'Modify driver details', false],
            'drivers.delete' => ['Deactivate a driver', 'Soft-delete a driver record', true],
            'drivers.export' => ['Export drivers', 'Download the driver registry', false],
        ],
        'visitors' => [
            'visitors.view'        => ['View visitors', 'Browse visitor records and passes', false],
            'visitors.create'      => ['Register a visitor', 'Add a new visitor record', false],
            'visitors.update'      => ['Edit a visitor', 'Modify visitor details', false],
            'visitors.delete'      => ['Deactivate a visitor', 'Soft-delete a visitor record', true],
            'visitors.issue_pass'  => ['Issue a visitor pass', 'Assign a temporary card and open a pass', false],
            'visitors.revoke_pass' => ['Revoke a visitor pass', 'Invalidate an issued pass immediately', true],
            'visitors.blacklist'   => ['Blacklist a visitor', 'Bar a visitor from future access', true],
            'visitors.export'      => ['Export visitors', 'Download visitor records', false],
        ],
        'rfid' => [
            'rfid.view'       => ['View RFID inventory', 'Browse tags and visitor cards', false],
            'rfid.create'     => ['Register RFID media', 'Add a tag or card to the inventory', false],
            'rfid.update'     => ['Edit RFID media', 'Modify tag or card details', false],
            'rfid.assign'     => ['Assign RFID media', 'Attach a tag to a vehicle or a card to a pass', false],
            'rfid.deactivate' => ['Deactivate RFID media', 'Disable a lost, damaged or expired tag or card', true],
            'rfid.delete'     => ['Delete RFID media', 'Remove an unused tag or card from the inventory', true],
            'rfid.export'     => ['Export RFID inventory', 'Download the tag and card inventory', false],
        ],
        'fingerprints' => [
            'fingerprints.view'   => ['View fingerprint enrolments', 'Browse enrolment and verification history', false],
            'fingerprints.enroll' => ['Enrol a fingerprint', 'Register a new biometric enrolment', false],
            'fingerprints.verify' => ['Verify a fingerprint', 'Run a verification against an enrolment', false],
            'fingerprints.delete' => ['Delete an enrolment', 'Remove a biometric enrolment', true],
            'fingerprints.sync'   => ['Synchronise templates', 'Reconcile the server records with a sensor', true],
        ],
        'devices' => [
            'devices.view'        => ['View devices', 'Browse ESP32 monitoring stations', false],
            'devices.create'      => ['Register a device', 'Add a monitoring station and issue its API key', true],
            'devices.update'      => ['Edit a device', 'Modify station configuration', false],
            'devices.delete'      => ['Decommission a device', 'Remove a station from service', true],
            'devices.rotate_key'  => ['Rotate an API key', 'Issue a new API key and revoke the previous one', true],
            'devices.suspend'     => ['Suspend a device', 'Temporarily bar a station from communicating', true],
            'devices.diagnostics' => ['View diagnostics', 'Inspect heartbeat history and health detail', false],
        ],
        'reports' => [
            'reports.view'     => ['View reports', 'Open the reporting module and analytics', false],
            'reports.generate' => ['Generate reports', 'Run a report against the monitoring history', false],
            'reports.export'   => ['Export reports', 'Download a report as PDF, Excel or CSV', false],
        ],
        'notifications' => [
            'notifications.view'   => ['View notifications', 'Open the notification centre', false],
            'notifications.manage' => ['Manage notifications', 'Mark read, archive and configure notifications', false],
            'notifications.delete' => ['Delete notifications', 'Permanently remove notifications', true],
        ],
        'audit' => [
            'audit.view'   => ['View audit trail', 'Read the immutable record of user actions', false],
            'audit.export' => ['Export audit trail', 'Download audit records', false],
        ],
        'security' => [
            'security.view'         => ['View security events', 'Read detected suspicious activity', false],
            'security.acknowledge'  => ['Acknowledge security events', 'Triage and resolve security events', false],
            'security.manage_rules' => ['Manage detection rules', 'Adjust detection thresholds and actions', true],
            'security.export'       => ['Export security events', 'Download security event records', false],
        ],
        'errors' => [
            'errors.view'    => ['View error logs', 'Read application error diagnostics', false],
            'errors.resolve' => ['Resolve error logs', 'Record a resolution against an error', false],
            'errors.export'  => ['Export error logs', 'Download error records', false],
        ],
        'users' => [
            'users.view'           => ['View users', 'Browse system user accounts', false],
            'users.create'         => ['Create users', 'Add a new system user', true],
            'users.update'         => ['Edit users', 'Modify user details and role assignment', true],
            'users.delete'         => ['Deactivate users', 'Soft-delete a user account', true],
            'users.reset_password' => ['Reset passwords', 'Issue a new password for another user', true],
            'users.lock'           => ['Lock accounts', 'Bar an account from signing in', true],
            'users.unlock'         => ['Unlock accounts', 'Restore a locked account', true],
            'users.sessions'       => ['Manage sessions', 'View and terminate active sessions', true],
            'users.export'         => ['Export users', 'Download the user directory', false],
        ],
        'roles' => [
            'roles.view'   => ['View roles', 'Browse access levels', false],
            'roles.create' => ['Create roles', 'Define a new access level', true],
            'roles.update' => ['Edit roles', 'Modify an access level', true],
            'roles.delete' => ['Delete roles', 'Remove an unused access level', true],
        ],
        'permissions' => [
            'permissions.view'   => ['View permissions', 'Browse the permission catalogue', false],
            'permissions.assign' => ['Assign permissions', 'Grant and revoke permissions on a role', true],
        ],
        'settings' => [
            'settings.view'   => ['View settings', 'Read runtime configuration', false],
            'settings.update' => ['Update settings', 'Change runtime configuration', true],
        ],
        'backup' => [
            'backup.view'     => ['View backups', 'Browse backup history', false],
            'backup.create'   => ['Create backups', 'Run a database backup', false],
            'backup.restore'  => ['Restore backups', 'Overwrite the live database from a backup', true],
            'backup.download' => ['Download backups', 'Retrieve a backup archive', true],
            'backup.delete'   => ['Delete backups', 'Permanently remove a backup archive', true],
        ],
        'api' => [
            'api.manage' => ['Manage API access', 'Configure API security and device credentials', true],
            'api.logs'   => ['View API logs', 'Read API request history and performance data', false],
        ],
        'profile' => [
            'profile.view'            => ['View own profile', 'Open the personal profile page', false],
            'profile.update'          => ['Update own profile', 'Change personal details', false],
            'profile.change_password' => ['Change own password', 'Set a new password for the signed-in account', false],
        ],
    ];

    /**
     * Roles and the permissions granted to each.
     *
     * "administrator" holds the wildcard, so a capability added in a future
     * release is covered without a data migration.
     *
     * @var array<string,array{name:string,description:string,priority:int,grants:list<string>}>
     */
    private const ROLES = [
        'administrator' => [
            'name'        => 'Administrator',
            'description' => 'Unrestricted access to every module, including security, settings and recovery.',
            'priority'    => 10,
            'grants'      => ['*'],
        ],
        'supervisor' => [
            'name'        => 'Supervisor',
            'description' => 'Oversees daily monitoring, manages the registry and reviews security events.',
            'priority'    => 20,
            'grants'      => [
                'dashboard.*', 'monitoring.*', 'vehicles.*', 'owners.*', 'drivers.*', 'visitors.*',
                'rfid.view', 'rfid.assign', 'rfid.deactivate', 'rfid.export',
                'fingerprints.view', 'fingerprints.verify',
                'devices.view', 'devices.diagnostics',
                'reports.*', 'notifications.view', 'notifications.manage',
                'audit.view', 'audit.export',
                'security.view', 'security.acknowledge', 'security.export',
                'errors.view',
                'users.view',
                'system.health',
                'profile.*',
            ],
        ],
        'security' => [
            'name'        => 'Security Personnel',
            'description' => 'Operates the monitoring dashboard during a shift. Cannot alter configuration or history.',
            'priority'    => 30,
            'grants'      => [
                'dashboard.view', 'dashboard.refresh',
                'monitoring.view', 'monitoring.export',
                'vehicles.view', 'vehicles.export',
                'owners.view',
                'drivers.view',
                'visitors.view', 'visitors.create', 'visitors.issue_pass',
                'rfid.view',
                'fingerprints.view', 'fingerprints.verify',
                'devices.view',
                'reports.view', 'reports.generate', 'reports.export',
                'notifications.view', 'notifications.manage',
                'profile.*',
            ],
        ],
        'auditor' => [
            'name'        => 'Auditor',
            'description' => 'Read-only access to monitoring history, audit records, security events and reports.',
            'priority'    => 25,
            'grants'      => [
                'dashboard.view',
                'monitoring.view', 'monitoring.export',
                'vehicles.view', 'owners.view', 'drivers.view', 'visitors.view', 'rfid.view',
                'fingerprints.view', 'devices.view', 'devices.diagnostics',
                'reports.view', 'reports.generate', 'reports.export',
                'audit.view', 'audit.export',
                'security.view', 'security.export',
                'errors.view', 'errors.export',
                'users.view', 'roles.view', 'permissions.view', 'settings.view',
                'backup.view', 'api.logs', 'system.health',
                'profile.*',
            ],
        ],
    ];

    public function description(): string
    {
        return 'Roles, permission catalogue and grants';
    }

    public function run(): void
    {
        $this->seedPermissions();
        $this->seedRoles();
        $this->seedGrants();
    }

    private function seedPermissions(): void
    {
        $order = 0;

        foreach (self::PERMISSIONS as $module => $permissions) {
            $moduleId = $this->idOf('system_modules', 'module_key', $module);

            foreach ($permissions as $key => [$name, $description, $dangerous]) {
                $this->upsert('permissions', [
                    'module_id'       => $moduleId,
                    'module_key'      => $module,
                    'permission_key'  => $key,
                    'permission_name' => $name,
                    'description'     => $description,
                    'is_dangerous'    => $dangerous ? 1 : 0,
                    'sort_order'      => $order += 10,
                    'status'          => 'active',
                ], ['permission_key']);
            }
        }
    }

    private function seedRoles(): void
    {
        foreach (self::ROLES as $slug => $definition) {
            $this->upsert('roles', [
                'role_slug'   => $slug,
                'role_name'   => $definition['name'],
                'description' => $definition['description'],
                'priority'    => $definition['priority'],
                'is_system'   => 1,
                'status'      => 'active',
            ], ['role_slug'], ['status']);
        }
    }

    /**
     * Grant each role its permissions, expanding "module.*" patterns.
     */
    private function seedGrants(): void
    {
        /** @var list<array<string,mixed>> $allPermissions */
        $allPermissions = $this->connection->select('SELECT `permission_id`, `permission_key` FROM `permissions`');

        $byKey = [];
        foreach ($allPermissions as $row) {
            $byKey[(string) $row['permission_key']] = (int) $row['permission_id'];
        }

        foreach (self::ROLES as $slug => $definition) {
            $roleId = $this->idOf('roles', 'role_slug', $slug);

            if ($roleId === null) {
                continue;
            }

            foreach ($this->expandGrants($definition['grants'], array_keys($byKey)) as $key) {
                if (!isset($byKey[$key])) {
                    continue;
                }

                $this->upsert('role_permissions', [
                    'role_id'       => $roleId,
                    'permission_id' => $byKey[$key],
                ], ['role_id', 'permission_id']);
            }
        }
    }

    /**
     * Expand "vehicles.*" into every permission key in that module.
     *
     * The literal "*" grant is kept as-is: it is a real permission row that
     * the authorisation guard treats as unrestricted access.
     *
     * @param list<string> $grants
     * @param list<string> $available
     *
     * @return list<string>
     */
    private function expandGrants(array $grants, array $available): array
    {
        $expanded = [];

        foreach ($grants as $grant) {
            if ($grant === '*') {
                $expanded[] = '*';
                continue;
            }

            if (str_ends_with($grant, '.*')) {
                $prefix = substr($grant, 0, -1);

                foreach ($available as $key) {
                    if (str_starts_with($key, $prefix)) {
                        $expanded[] = $key;
                    }
                }

                continue;
            }

            $expanded[] = $grant;
        }

        return array_values(array_unique($expanded));
    }

    /**
     * The permission catalogue, exposed for the security-audit command which
     * verifies that every route's declared permission actually exists.
     *
     * @return list<string>
     */
    public static function permissionKeys(): array
    {
        $keys = [];

        foreach (self::PERMISSIONS as $permissions) {
            foreach (array_keys($permissions) as $key) {
                $keys[] = $key;
            }
        }

        return $keys;
    }
}
