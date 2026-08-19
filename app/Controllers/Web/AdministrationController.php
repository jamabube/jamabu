<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\AuditLogRepository;
use App\Repositories\DepartmentRepository;
use App\Repositories\PermissionRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserSessionRepository;
use App\Services\BackupService;
use App\Services\RoleService;
use App\Services\SettingsService;
use App\Services\UserService;

/**
 * Administration pages: users, roles, permissions, departments, settings and
 * backups.
 *
 * @package App\Controllers\Web
 * @version 1.0.0
 */
final class AdministrationController extends Controller
{
    /**
     * GET /users
     */
    public function users(Request $request): Response
    {
        return $this->render('pages/administration/users', [
            'title'       => 'Users',
            'summary'     => $this->service(UserService::class)->summary(),
            'roles'       => $this->service(RoleService::class)->assignableRoles(),
            'departments' => $this->service(DepartmentRepository::class)->selectList(),
            'can'         => [
                'create'   => $this->auth->can('users.create'),
                'update'   => $this->auth->can('users.update'),
                'delete'   => $this->auth->can('users.delete'),
                'lock'     => $this->auth->can('users.lock'),
                'unlock'   => $this->auth->can('users.unlock'),
                'reset'    => $this->auth->can('users.reset_password'),
                'sessions' => $this->auth->can('users.sessions'),
                'export'   => $this->auth->can('users.export'),
            ],
        ]);
    }

    /**
     * GET /users/{id}
     */
    public function user(Request $request): Response
    {
        $userId = $request->routeInt('id');

        return $this->render('pages/administration/user-detail', [
            'title'    => 'User detail',
            'profile'  => $this->service(UserService::class)->profile($userId),
            'sessions' => $this->service(UserSessionRepository::class)->historyFor($userId),
            'activity' => $this->service(AuditLogRepository::class)->forUser($userId, 25),
        ]);
    }

    /**
     * GET /roles
     */
    public function roles(Request $request): Response
    {
        return $this->render('pages/administration/roles', [
            'title'   => 'Roles',
            'roles'   => $this->service(RoleService::class)->all(),
            'modules' => $this->service(PermissionRepository::class)->groupedByModule(),
            'can'     => [
                'create' => $this->auth->can('roles.create'),
                'update' => $this->auth->can('roles.update'),
                'delete' => $this->auth->can('roles.delete'),
                'assign' => $this->auth->can('permissions.assign'),
            ],
        ]);
    }

    /**
     * GET /roles/{id}
     */
    public function role(Request $request): Response
    {
        $roleId = $request->routeInt('id');

        return $this->render('pages/administration/role-detail', [
            'title'     => 'Role detail',
            'matrix'    => $this->service(RoleService::class)->matrix($roleId),
            'members'   => $this->service(RoleRepository::class)->members($roleId),
            'canAssign' => $this->auth->can('permissions.assign'),
        ]);
    }

    /**
     * GET /permissions
     */
    public function permissions(Request $request): Response
    {
        $repository = $this->service(PermissionRepository::class);

        return $this->render('pages/administration/permissions', [
            'title'      => 'Permissions',
            'modules'    => $repository->groupedByModule(),
            'roleCounts' => $repository->roleCounts(),
            'roles'      => $this->service(RoleService::class)->all(),
        ]);
    }

    /**
     * GET /departments
     */
    public function departments(Request $request): Response
    {
        return $this->render('pages/administration/departments', [
            'title' => 'Departments',
            'can'   => [
                'create' => $this->auth->can('users.create'),
                'update' => $this->auth->can('users.update'),
                'delete' => $this->auth->can('users.delete'),
            ],
        ]);
    }

    /**
     * GET /settings
     */
    public function settings(Request $request): Response
    {
        return $this->render('pages/administration/settings', [
            'title'     => 'System settings',
            'groups'    => $this->service(SettingsService::class)->groupedForDisplay(),
            'canUpdate' => $this->auth->can('settings.update'),
        ]);
    }

    /**
     * GET /backups
     */
    public function backups(Request $request): Response
    {
        return $this->render('pages/administration/backups', [
            'title'   => 'Backup and restore',
            'summary' => $this->service(BackupService::class)->summary(),
            'can'     => [
                'create'   => $this->auth->can('backup.create'),
                'restore'  => $this->auth->can('backup.restore'),
                'download' => $this->auth->can('backup.download'),
                'delete'   => $this->auth->can('backup.delete'),
            ],
        ]);
    }
}
