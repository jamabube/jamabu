<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Security\AuthGuard;
use App\DTO\AuthenticatedUser;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use Tests\TestCase;

/**
 * Verifies role-based access control against the seeded permission catalogue.
 *
 * The negative assertions matter most: a role that can reach something it
 * should not is a privilege-escalation defect, and a test that only checks the
 * positive cases would never notice.
 *
 * @package Tests\Integration
 * @version 1.0.0
 */
final class AuthorizationTest extends TestCase
{
    protected bool $requiresDatabase = true;

    private AuthGuard $guard;
    private UserRepository $users;
    private RoleRepository $roles;

    public function description(): string
    {
        return 'Role-based access control and the permission catalogue';
    }

    public function setUp(): void
    {
        $this->guard = $this->app->make(AuthGuard::class);
        $this->users = $this->app->make(UserRepository::class);
        $this->roles = $this->app->make(RoleRepository::class);
    }

    public function tearDown(): void
    {
        $this->guard->clear();
    }

    /**
     * Bind the guard to a role's permission set without needing a real user.
     */
    private function assumeRole(string $slug): void
    {
        $role = $this->roles->findBySlug($slug);

        if ($role === null) {
            return;
        }

        $this->guard->setUser(
            new AuthenticatedUser(
                id: 0,
                username: 'test-' . $slug,
                fullName: 'Test ' . $slug,
                email: 'test@forestlawn.local',
                roleId: (int) $role['role_id'],
                roleName: (string) $role['role_name'],
                roleSlug: $slug
            ),
            $this->roles->permissionKeys((int) $role['role_id'])
        );
    }

    public function testAdministratorHoldsUnrestrictedAccess(): void
    {
        $this->assumeRole('administrator');

        $this->assertTrue($this->guard->can('backup.restore'), 'the administrator can restore a backup');
        $this->assertTrue($this->guard->can('users.delete'), 'the administrator can remove a user');
        $this->assertTrue($this->guard->can('settings.update'), 'the administrator can change settings');

        // The wildcard grant is what makes a capability added in a later
        // release reachable without a data migration.
        $this->assertTrue(
            $this->guard->can('a.permission.that.does.not.exist.yet'),
            'the wildcard covers permissions introduced later'
        );
    }

    public function testSecurityPersonnelAreConfinedToOperations(): void
    {
        $this->assumeRole('security');

        $this->assertTrue($this->guard->can('monitoring.view'), 'a guard can watch the monitoring feed');
        $this->assertTrue($this->guard->can('vehicles.view'), 'a guard can look up a vehicle');
        $this->assertTrue($this->guard->can('visitors.issue_pass'), 'a guard can issue a visitor pass');
        $this->assertTrue($this->guard->can('reports.generate'), 'a guard can produce a report');

        $this->assertFalse($this->guard->can('users.create'), 'a guard cannot create a user');
        $this->assertFalse($this->guard->can('users.delete'), 'a guard cannot remove a user');
        $this->assertFalse($this->guard->can('settings.update'), 'a guard cannot change settings');
        $this->assertFalse($this->guard->can('backup.restore'), 'a guard cannot restore a backup');
        $this->assertFalse($this->guard->can('roles.update'), 'a guard cannot edit a role');
        $this->assertFalse($this->guard->can('devices.rotate_key'), 'a guard cannot rotate a device key');
        $this->assertFalse($this->guard->can('monitoring.force_close'), 'a guard cannot force-close a visit');
        $this->assertFalse($this->guard->can('audit.view'), 'a guard cannot read the audit trail');
    }

    public function testAuditorIsReadOnly(): void
    {
        $this->assumeRole('auditor');

        $this->assertTrue($this->guard->can('audit.view'), 'an auditor can read the audit trail');
        $this->assertTrue($this->guard->can('security.view'), 'an auditor can read security events');
        $this->assertTrue($this->guard->can('settings.view'), 'an auditor can read the settings');

        $this->assertFalse($this->guard->can('settings.update'), 'an auditor cannot change settings');
        $this->assertFalse($this->guard->can('vehicles.create'), 'an auditor cannot register a vehicle');
        $this->assertFalse($this->guard->can('security.acknowledge'), 'an auditor cannot triage a security event');
        $this->assertFalse($this->guard->can('backup.create'), 'an auditor cannot run a backup');
    }

    public function testSupervisorSitsBetweenTheTwo(): void
    {
        $this->assumeRole('supervisor');

        $this->assertTrue($this->guard->can('vehicles.create'), 'a supervisor can register a vehicle');
        $this->assertTrue($this->guard->can('security.acknowledge'), 'a supervisor can triage a security event');
        $this->assertTrue($this->guard->can('monitoring.force_close'), 'a supervisor can force-close a visit');

        $this->assertFalse($this->guard->can('users.create'), 'a supervisor cannot create a user');
        $this->assertFalse($this->guard->can('settings.update'), 'a supervisor cannot change settings');
        $this->assertFalse($this->guard->can('backup.restore'), 'a supervisor cannot restore a backup');
    }

    public function testGuestHoldsNothing(): void
    {
        $this->guard->clear();

        $this->assertTrue($this->guard->guest(), 'no principal is bound');
        $this->assertFalse($this->guard->can('dashboard.view'), 'an unauthenticated caller cannot view the dashboard');
        $this->assertFalse($this->guard->can('*'), 'an unauthenticated caller does not hold the wildcard');
    }

    public function testRolePriorityPreventsUpwardAssignment(): void
    {
        $supervisor = $this->roles->findBySlug('supervisor');
        $assignable = $this->roles->assignableBy((int) $supervisor['priority']);
        $slugs      = array_column($assignable, 'role_slug');

        // A supervisor offering the administrator role in a drop-down would be
        // a direct escalation path.
        $this->assertFalse(
            in_array('administrator', $slugs, true),
            'a supervisor cannot assign the administrator role'
        );

        $this->assertContains('security', $slugs, 'a supervisor can assign the security role');
    }

    public function testEveryGrantedPermissionExistsInTheCatalogue(): void
    {
        $catalogue = $this->app->make(PermissionRepository::class)->allKeys();
        $unknown   = [];

        foreach ($this->roles->allWithCounts() as $role) {
            foreach ($this->roles->permissionKeys((int) $role['role_id']) as $key) {
                if (!in_array($key, $catalogue, true)) {
                    $unknown[] = $role['role_slug'] . ' => ' . $key;
                }
            }
        }

        $this->assertSame([], $unknown, 'no role holds a permission the catalogue does not define');
    }

    public function testPermissionsAreResolvedForARealUser(): void
    {
        $administrator = $this->users->findForAuthentication('administrator');

        if ($administrator === null) {
            $this->assertTrue(false, 'the seeded administrator account exists');

            return;
        }

        $permissions = $this->users->permissionsFor((int) $administrator['user_id']);

        $this->assertContains('*', $permissions, 'the administrator account resolves the wildcard grant');
    }
}
