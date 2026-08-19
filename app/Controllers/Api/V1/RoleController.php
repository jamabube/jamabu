<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleRepository;
use App\Responses\ApiResponse;
use App\Services\RoleService;

/**
 * Role and permission administration.
 *
 * Permissions are granted to roles, never to people. That is what keeps the
 * question "what can a guard do?" answerable in one place instead of having to
 * be reconstructed account by account.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class RoleController extends Controller
{
    /**
     * GET /api/v1/roles
     */
    public function index(Request $request): JsonResponse
    {
        return $this->json('Roles retrieved.', $this->service(RoleService::class)->all());
    }

    /**
     * GET /api/v1/roles/{id}
     */
    public function show(Request $request): JsonResponse
    {
        $roleId = $request->routeInt('id');

        return $this->json('Role retrieved.', [
            'matrix'  => $this->service(RoleService::class)->matrix($roleId),
            'members' => $this->service(RoleRepository::class)->members($roleId),
        ]);
    }

    /**
     * GET /api/v1/permissions
     */
    public function permissions(Request $request): JsonResponse
    {
        $repository = $this->service(PermissionRepository::class);

        return $this->json('Permissions retrieved.', [
            'modules'     => $repository->groupedByModule(),
            'role_counts' => $repository->roleCounts(),
        ]);
    }

    /**
     * POST /api/v1/roles
     */
    public function store(Request $request): JsonResponse
    {
        $attributes = $this->validate($request, [
            'role_name'   => 'required|string|min:3|max:60|unique:roles,role_name',
            'role_slug'   => 'nullable|slug|max:60|unique:roles,role_slug',
            'description' => 'nullable|string|max:255',
            'priority'    => 'nullable|integer|between:1,999',
            'status'      => 'nullable|in:active,inactive',
            'permissions' => 'nullable|array',
        ], [
            'role_name'   => 'Role name',
            'role_slug'   => 'Role identifier',
            'priority'    => 'Authority level',
            'permissions' => 'Permissions',
        ]);

        $roles  = $this->service(RoleService::class);
        $roleId = $roles->create(array_diff_key($attributes, ['permissions' => null]));

        // Creating a role and granting it nothing produces an account that can
        // sign in and do nothing at all, so the grants travel with the create
        // when the interface sends them.
        if (isset($attributes['permissions']) && is_array($attributes['permissions'])) {
            $roles->syncPermissions($roleId, $this->permissionKeys($attributes['permissions']));
        }

        return ApiResponse::created('The role was created.', ['role_id' => $roleId], '/api/v1/roles/' . $roleId);
    }

    /**
     * PUT /api/v1/roles/{id}
     */
    public function update(Request $request): JsonResponse
    {
        $roleId = $request->routeInt('id');

        $attributes = $this->validate($request, [
            'role_name'   => 'required|string|min:3|max:60|unique:roles,role_name,' . $roleId . ',role_id',
            'description' => 'nullable|string|max:255',
            'priority'    => 'nullable|integer|between:1,999',
            'status'      => 'nullable|in:active,inactive',
        ], [
            'role_name' => 'Role name',
            'priority'  => 'Authority level',
        ]);

        $this->service(RoleService::class)->update($roleId, $attributes);

        return $this->json('The role was updated.', ['role_id' => $roleId]);
    }

    /**
     * PUT /api/v1/roles/{id}/permissions
     */
    public function syncPermissions(Request $request): JsonResponse
    {
        $roleId = $request->routeInt('id');

        $payload = $this->validate($request, [
            'permissions' => 'required|array',
        ], [
            'permissions' => 'Permissions',
        ]);

        $keys = $this->permissionKeys(is_array($payload['permissions']) ? $payload['permissions'] : []);

        $this->service(RoleService::class)->syncPermissions($roleId, $keys);

        return $this->json('The role\'s permissions were updated.', [
            'role_id'     => $roleId,
            'permissions' => count($keys),
        ]);
    }

    /**
     * POST /api/v1/roles/{id}/duplicate
     *
     * Copying an existing role is how a new one is normally made: starting from
     * a working set of permissions is safer than assembling one from nothing
     * and discovering the omissions at the gate.
     */
    public function duplicate(Request $request): JsonResponse
    {
        $roleId = $request->routeInt('id');

        $payload = $this->validate($request, [
            'role_name' => 'required|string|min:3|max:60|unique:roles,role_name',
        ], [
            'role_name' => 'New role name',
        ]);

        $newRoleId = $this->service(RoleService::class)->duplicate($roleId, (string) $payload['role_name']);

        return ApiResponse::created('The role was duplicated.', ['role_id' => $newRoleId]);
    }

    /**
     * DELETE /api/v1/roles/{id}
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->service(RoleService::class)->delete($request->routeInt('id'));

        return ApiResponse::deleted('The role was removed.');
    }

    /**
     * Reduce a submitted permission list to clean, unique keys.
     *
     * @param array<array-key,mixed> $submitted
     *
     * @return list<string>
     */
    private function permissionKeys(array $submitted): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $key): string => is_string($key) ? trim($key) : '',
            $submitted
        ))));
    }
}
