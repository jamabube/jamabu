<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Security\AuthGuard;
use App\Core\Support\Str;
use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserSessionRepository;

/**
 * Role and permission administration.
 *
 * @package App\Services
 * @version 1.0.0
 */
class RoleService
{
    public function __construct(
        private readonly RoleRepository $roles,
        private readonly PermissionRepository $permissions,
        private readonly UserSessionRepository $sessions,
        private readonly AuditService $audit,
        private readonly AuthGuard $auth
    ) {
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->roles->allWithCounts();
    }

    /**
     * The permission matrix for one role.
     *
     * @return array<string,mixed>
     */
    public function matrix(int $roleId): array
    {
        $role = $this->roles->find($roleId);

        if ($role === null) {
            throw NotFoundException::record('Role', $roleId);
        }

        $granted = $this->roles->permissionKeys($roleId);

        return [
            'role'        => $role,
            'modules'     => $this->permissions->groupedByModule(),
            'granted'     => $granted,
            // A role holding the wildcard has every capability by definition,
            // so the matrix is shown fully checked and read-only rather than
            // implying the individual boxes mean anything.
            'unrestricted'=> in_array('*', $granted, true),
            'members'     => $this->roles->members($roleId, 25),
        ];
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function create(array $attributes): int
    {
        $slug = Str::slug((string) ($attributes['role_slug'] ?? $attributes['role_name'] ?? ''), '_');

        if ($slug === '') {
            throw new \App\Exceptions\ValidationException(['role_name' => ['A role name is required.']]);
        }

        if ($this->roles->existsWhere('role_slug', $slug, null)) {
            throw ConflictException::duplicate('role', 'identifier', $slug);
        }

        $priority = (int) ($attributes['priority'] ?? 100);
        $this->assertPriorityPermitted($priority);

        $roleId = $this->roles->create([
            'role_slug'   => $slug,
            'role_name'   => (string) ($attributes['role_name'] ?? Str::title($slug)),
            'description' => $attributes['description'] ?? null,
            'priority'    => $priority,
            'status'      => (string) ($attributes['status'] ?? 'active'),
            'created_by'  => $this->auth->id(),
            'updated_by'  => $this->auth->id(),
        ]);

        $this->audit->created('roles', 'roles', $roleId, sprintf(
            'Role "%s" was created.',
            (string) ($attributes['role_name'] ?? $slug)
        ), ['role_slug' => $slug, 'priority' => $priority]);

        return $roleId;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function update(int $roleId, array $attributes): void
    {
        $role = $this->roles->findOrFail($roleId);

        // A system role's identifier is quoted in code and seed data; renaming
        // its slug would silently break those references.
        if ((int) $role['is_system'] === 1) {
            unset($attributes['role_slug'], $attributes['priority']);
        }

        if (isset($attributes['priority'])) {
            $this->assertPriorityPermitted((int) $attributes['priority']);
        }

        $this->roles->update($roleId, array_merge($attributes, ['updated_by' => $this->auth->id()]));

        $this->audit->updated('roles', 'roles', $roleId, sprintf(
            'Role "%s" was updated.',
            (string) $role['role_name']
        ), $role, $attributes);
    }

    /**
     * Replace a role's grants.
     *
     * @param list<string> $permissionKeys
     */
    public function syncPermissions(int $roleId, array $permissionKeys): void
    {
        $role = $this->roles->findOrFail($roleId);

        // Only somebody holding unrestricted access may grant it.
        if (in_array('*', $permissionKeys, true) && $this->auth->cannot('*')) {
            throw new AuthorizationException('You may not grant unrestricted system access.');
        }

        $this->assertNotOwnRole($roleId, 'change the permissions of');

        $permissionIds = $this->permissions->idsForKeys($permissionKeys);
        $result        = $this->roles->syncPermissions($roleId, $permissionIds, $this->auth->id());

        if ($result['granted'] === [] && $result['revoked'] === []) {
            return;
        }

        $this->audit->record('permissions', 'assigned', sprintf(
            'Permissions for role "%s" were changed: %d granted, %d revoked.',
            (string) $role['role_name'],
            count($result['granted']),
            count($result['revoked'])
        ), [
            'record_type' => 'roles',
            'record_id'   => $roleId,
            'new'         => ['permissions' => $permissionKeys],
        ]);

        // Everyone holding this role is carrying a permission cache that is now
        // wrong, so their sessions end rather than running on stale authority.
        $this->endSessionsForRole($roleId);
    }

    /**
     * @throws BusinessRuleException
     */
    public function delete(int $roleId): void
    {
        $role = $this->roles->findOrFail($roleId);

        if ((int) $role['is_system'] === 1) {
            throw BusinessRuleException::withCode(
                'SYSTEM_ROLE',
                sprintf('"%s" is a system role and cannot be deleted.', (string) $role['role_name'])
            );
        }

        $members = $this->roles->userCount($roleId);

        // Deleting a role that people hold would leave those accounts pointing
        // at nothing; the foreign key would refuse it anyway, but the message
        // here tells the administrator what to do about it.
        if ($members > 0) {
            throw BusinessRuleException::withCode(
                'ROLE_IN_USE',
                sprintf(
                    '%d account(s) hold this role. Reassign them before deleting it.',
                    $members
                )
            );
        }

        $this->assertNotOwnRole($roleId, 'delete');

        $this->roles->delete($roleId, $this->auth->id());

        $this->audit->deleted('roles', 'roles', $roleId, sprintf(
            'Role "%s" was deleted.',
            (string) $role['role_name']
        ), ['role_slug' => $role['role_slug']]);
    }

    /**
     * Copy a role and its grants.
     */
    public function duplicate(int $roleId, string $newName): int
    {
        $role = $this->roles->findOrFail($roleId);
        $slug = Str::slug($newName, '_');

        if ($this->roles->existsWhere('role_slug', $slug, null)) {
            throw ConflictException::duplicate('role', 'identifier', $slug);
        }

        $newRoleId = $this->roles->create([
            'role_slug'   => $slug,
            'role_name'   => $newName,
            'description' => sprintf('Copied from "%s".', (string) $role['role_name']),
            'priority'    => (int) $role['priority'],
            'status'      => 'active',
            'created_by'  => $this->auth->id(),
            'updated_by'  => $this->auth->id(),
        ]);

        $this->roles->syncPermissions($newRoleId, $this->roles->permissionIds($roleId), $this->auth->id());

        $this->audit->created('roles', 'roles', $newRoleId, sprintf(
            'Role "%s" was created as a copy of "%s".',
            $newName,
            (string) $role['role_name']
        ), ['role_slug' => $slug]);

        return $newRoleId;
    }

    /**
     * Roles the signed-in user is permitted to assign.
     *
     * @return list<array<string,mixed>>
     */
    public function assignableRoles(): array
    {
        if ($this->auth->can('*')) {
            return $this->roles->query()
                ->select(['role_id', 'role_slug', 'role_name', 'description', 'priority'])
                ->whereEquals('status', 'active')
                ->orderBy('priority')
                ->get();
        }

        $actor     = $this->auth->user();
        $actorRole = $actor === null ? null : $this->roles->find($actor->roleId);

        return $this->roles->assignableBy($actorRole === null ? PHP_INT_MAX : (int) $actorRole['priority']);
    }

    /**
     * A role cannot be given more authority than the person editing it holds.
     *
     * @throws AuthorizationException
     */
    private function assertPriorityPermitted(int $priority): void
    {
        if ($this->auth->can('*')) {
            return;
        }

        $actor     = $this->auth->user();
        $actorRole = $actor === null ? null : $this->roles->find($actor->roleId);

        if ($actorRole !== null && $priority < (int) $actorRole['priority']) {
            throw new AuthorizationException(
                'You may not create or modify a role with more authority than your own.'
            );
        }
    }

    /**
     * Editing one's own role is how an escalation is usually attempted.
     *
     * @throws AuthorizationException
     */
    private function assertNotOwnRole(int $roleId, string $action): void
    {
        if ($this->auth->can('*')) {
            return;
        }

        if ($this->auth->user()?->roleId === $roleId) {
            throw new AuthorizationException(sprintf('You may not %s your own role.', $action));
        }
    }

    /**
     * End every session held by members of a role.
     */
    private function endSessionsForRole(int $roleId): void
    {
        foreach ($this->roles->members($roleId, 1000) as $member) {
            $this->sessions->closeAllFor(
                (int) $member['user_id'],
                'administrator',
                null,
                $this->auth->id()
            );
        }
    }
}
