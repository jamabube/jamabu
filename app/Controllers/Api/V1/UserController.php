<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\AuditLogRepository;
use App\Repositories\UserSessionRepository;
use App\Responses\ApiResponse;
use App\Services\RoleService;
use App\Services\UserService;

/**
 * User account administration.
 *
 * Accounts are never hard-deleted. Audit records name the person who did each
 * thing, and a deleted row would turn years of accountable history into
 * anonymous history.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class UserController extends Controller
{
    /**
     * GET /api/v1/users
     */
    public function index(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'created_at');
        $paginator = $this->service(UserService::class)->paginate([
            'search'        => $request->string('search'),
            'status'        => $request->string('status'),
            'role_id'       => $request->string('role_id'),
            'department_id' => $request->string('department_id'),
            'locked'        => $request->string('locked'),
            'date_from'     => $request->string('date_from'),
            'date_to'       => $request->string('date_to'),
        ], $options);

        return $this->paginated('Users retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/users/summary
     */
    public function summary(Request $request): JsonResponse
    {
        return $this->json('User summary retrieved.', $this->service(UserService::class)->summary());
    }

    /**
     * GET /api/v1/users/assignable-roles
     *
     * A user may only create accounts at or below their own authority, so the
     * role list is filtered rather than the choice being rejected on submit.
     */
    public function assignableRoles(Request $request): JsonResponse
    {
        return $this->json('Assignable roles retrieved.', $this->service(RoleService::class)->assignableRoles());
    }

    /**
     * GET /api/v1/users/{id}
     */
    public function show(Request $request): JsonResponse
    {
        return $this->json('User retrieved.', $this->service(UserService::class)->profile($request->routeInt('id')));
    }

    /**
     * GET /api/v1/users/{id}/activity
     */
    public function activity(Request $request): JsonResponse
    {
        $userId = $request->routeInt('id');

        return $this->json('User activity retrieved.', [
            'audit'    => $this->service(AuditLogRepository::class)->forUser($userId),
            'sessions' => $this->service(UserSessionRepository::class)->historyFor($userId),
        ]);
    }

    /**
     * POST /api/v1/users
     */
    public function store(Request $request): JsonResponse
    {
        $attributes = $this->validate($request, array_merge($this->rules(), [
            'username' => 'required|alpha_dash|min:3|max:50|unique:users,username',
            'email'    => 'required|email|max:150|unique:users,email',
            'password' => 'nullable|string|max:128',
        ]), $this->labels());

        $password = isset($attributes['password']) && is_string($attributes['password']) && $attributes['password'] !== ''
            ? $attributes['password']
            : null;

        $result = $this->service(UserService::class)->create(
            array_diff_key($attributes, ['password' => null]),
            $password
        );

        // A generated password is returned once so the administrator can pass
        // it on; the account must change it at first sign-in either way.
        return ApiResponse::created('The account was created.', [
            'user_id'            => $result['user_id'],
            'password'           => $password === null ? $result['password'] : null,
            'password_generated' => $password === null,
        ], '/api/v1/users/' . $result['user_id']);
    }

    /**
     * PUT /api/v1/users/{id}
     */
    public function update(Request $request): JsonResponse
    {
        $userId = $request->routeInt('id');

        $attributes = $this->validate($request, array_merge($this->rules(), [
            'email' => 'required|email|max:150|unique:users,email,' . $userId . ',user_id',
        ]), $this->labels());

        $this->service(UserService::class)->update($userId, $attributes);

        return $this->json('The account was updated.', ['user_id' => $userId]);
    }

    /**
     * POST /api/v1/users/{id}/reset-password
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $userId = $request->routeInt('id');

        $payload = $this->validate($request, [
            'password' => 'nullable|string|max:128',
        ], [
            'password' => 'Password',
        ]);

        $supplied = isset($payload['password']) && is_string($payload['password']) && $payload['password'] !== ''
            ? $payload['password']
            : null;

        $password = $this->service(UserService::class)->resetPassword($userId, $supplied);

        return $this->json('The password was reset and every other session signed out.', [
            'user_id'            => $userId,
            'password'           => $supplied === null ? $password : null,
            'password_generated' => $supplied === null,
        ]);
    }

    /**
     * POST /api/v1/users/{id}/lock
     */
    public function lock(Request $request): JsonResponse
    {
        $userId = $request->routeInt('id');

        $payload = $this->validate($request, [
            'reason' => 'required|string|min:5|max:255',
        ], [
            'reason' => 'Reason',
        ]);

        $this->service(UserService::class)->lock($userId, (string) $payload['reason']);

        return $this->json('The account was locked.', ['user_id' => $userId]);
    }

    /**
     * POST /api/v1/users/{id}/unlock
     */
    public function unlock(Request $request): JsonResponse
    {
        $userId = $request->routeInt('id');

        $this->service(UserService::class)->unlock($userId);

        return $this->json('The account was unlocked.', ['user_id' => $userId]);
    }

    /**
     * DELETE /api/v1/users/{id}
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->service(UserService::class)->deactivate($request->routeInt('id'));

        return ApiResponse::deleted('The account was deactivated.');
    }

    /**
     * POST /api/v1/users/{id}/restore
     */
    public function restore(Request $request): JsonResponse
    {
        $userId = $request->routeInt('id');

        $this->service(UserService::class)->restore($userId);

        return $this->json('The account was restored.', ['user_id' => $userId]);
    }

    /**
     * GET /api/v1/users/sessions
     */
    public function sessions(Request $request): JsonResponse
    {
        $sessions = $this->service(UserSessionRepository::class)->allActive(
            min(500, max(1, $request->integer('limit', 200)))
        );

        return $this->json('Active sessions retrieved.', $sessions, 200, [
            'count' => count($sessions),
        ]);
    }

    /**
     * DELETE /api/v1/users/{id}/sessions/{session}
     */
    public function terminateSession(Request $request): JsonResponse
    {
        $userId    = $request->routeInt('id');
        $sessionId = $request->routeInt('session');

        $this->service(UserService::class)->terminateSession($sessionId, $userId);

        return $this->json('The session was signed out.', [
            'user_id'    => $userId,
            'session_id' => $sessionId,
        ]);
    }

    /**
     * @return array<string,string>
     */
    private function rules(): array
    {
        return [
            'first_name'      => 'required|alpha_space|max:60',
            'middle_name'     => 'nullable|alpha_space|max:60',
            'last_name'       => 'required|alpha_space|max:60',
            'suffix'          => 'nullable|string|max:10',
            'gender'          => 'nullable|in:male,female,other,undisclosed',
            'birth_date'      => 'nullable|date',
            'mobile_number'   => 'nullable|phone',
            'employee_number' => 'nullable|string|max:30',
            'role_id'         => 'required|integer|exists:roles,role_id',
            'department_id'   => 'nullable|integer|exists:departments,department_id',
            'position'        => 'nullable|string|max:80',
            'status'          => 'nullable|in:active,inactive,suspended',
            'remarks'         => 'nullable|string|max:2000',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function labels(): array
    {
        return [
            'first_name'      => 'First name',
            'last_name'       => 'Last name',
            'employee_number' => 'Employee number',
            'role_id'         => 'Role',
            'department_id'   => 'Department',
            'mobile_number'   => 'Mobile number',
        ];
    }
}
