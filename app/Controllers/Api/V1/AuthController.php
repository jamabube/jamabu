<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Services\AuthenticationService;
use App\Services\UserService;

/**
 * Authentication endpoints for API clients.
 *
 * These share the session mechanism the web interface uses rather than issuing
 * bearer tokens: the API is consumed by the same browser, over the same LAN,
 * and a second credential type would be a second thing to get wrong.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class AuthController extends Controller
{
    /**
     * POST /api/v1/login
     */
    public function login(Request $request): JsonResponse
    {
        $credentials = $this->validate($request, [
            'username' => 'required|string|max:50',
            'password' => 'required|string|max:128',
            'remember' => 'nullable|boolean',
        ], [
            'username' => 'Username',
            'password' => 'Password',
        ]);

        $user = $this->service(AuthenticationService::class)->attempt(
            (string) $credentials['username'],
            (string) $credentials['password'],
            $request,
            (bool) ($credentials['remember'] ?? false)
        );

        return $this->json('Signed in successfully.', [
            'user'                    => $user->toArray(),
            'permissions'             => $this->auth->permissions(),
            'must_change_password'    => $this->service(AuthenticationService::class)->requiresPasswordChange($user),
            'csrf_token'              => csrf_token(),
            'session_expires_in'      => (int) config('session.lifetime', 1800),
        ]);
    }

    /**
     * POST /api/v1/logout
     */
    public function logout(Request $request): JsonResponse
    {
        $this->service(AuthenticationService::class)->logout();

        return $this->json('Signed out successfully.');
    }

    /**
     * GET /api/v1/session
     *
     * Lets the interface confirm the session is still valid and learn how long
     * it has left, which is what drives the idle-timeout warning.
     */
    public function session(Request $request): JsonResponse
    {
        $user = $this->auth->user();

        if ($user === null) {
            return $this->failure('UNAUTHENTICATED', 'No active session.', 401);
        }

        $lifetime = (int) config('session.lifetime', 1800);

        return $this->json('Session is active.', [
            'user'          => $user->toArray(),
            'permissions'   => $this->auth->permissions(),
            'expires_in'    => $this->session->secondsUntilIdleTimeout($lifetime),
            'warn_at'       => (int) config('session.idle_warning_seconds', 120),
            'csrf_token'    => csrf_token(),
            'server_time'   => now()->format(DATE_ATOM),
        ]);
    }

    /**
     * POST /api/v1/session/extend
     *
     * Refreshes the activity marker after the user confirms they are still
     * there, so a long report review does not end in a surprise sign-out.
     */
    public function extendSession(Request $request): JsonResponse
    {
        $this->session->touch();

        return $this->json('Session extended.', [
            'expires_in' => (int) config('session.lifetime', 1800),
        ]);
    }

    /**
     * GET /api/v1/profile
     */
    public function profile(Request $request): JsonResponse
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            return $this->failure('UNAUTHENTICATED', 'No active session.', 401);
        }

        return $this->json('Profile retrieved.', $this->service(UserService::class)->profile($userId));
    }

    /**
     * PUT /api/v1/profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            return $this->failure('UNAUTHENTICATED', 'No active session.', 401);
        }

        // Deliberately narrow: a user may correct their own contact details,
        // but role, status and username are administrative concerns.
        $attributes = $this->validate($request, [
            'first_name'    => 'required|string|max:60',
            'middle_name'   => 'nullable|string|max:60',
            'last_name'     => 'required|string|max:60',
            'email'         => 'required|email|max:150',
            'mobile_number' => 'nullable|phone',
        ], [
            'first_name' => 'First name',
            'last_name'  => 'Last name',
            'email'      => 'Email address',
        ]);

        $this->service(UserService::class)->update($userId, $attributes);

        return $this->json('Your profile was updated.');
    }

    /**
     * POST /api/v1/password/change
     */
    public function changePassword(Request $request): JsonResponse
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            return $this->failure('UNAUTHENTICATED', 'No active session.', 401);
        }

        $payload = $this->validate($request, [
            'current_password'          => 'required|string|max:128',
            'new_password'              => 'required|string|max:128|confirmed',
            'new_password_confirmation' => 'required|string|max:128',
        ], [
            'current_password' => 'Current password',
            'new_password'     => 'New password',
        ]);

        $this->service(UserService::class)->changeOwnPassword(
            $userId,
            (string) $payload['current_password'],
            (string) $payload['new_password']
        );

        return $this->json('Your password was changed. Other sessions have been signed out.');
    }

    /**
     * POST /api/v1/password/strength
     *
     * Scores a candidate without storing it, so the interface can show a meter
     * and the policy failures as the user types rather than only on submit.
     */
    public function passwordStrength(Request $request): JsonResponse
    {
        $candidate = $request->string('password');
        $policy    = $this->service(\App\Services\PasswordPolicyService::class);
        $score     = $policy->strength($candidate);

        return $this->json('Password assessed.', [
            'score'    => $score,
            'label'    => $policy->strengthLabel($score),
            'failures' => $policy->check($candidate, $this->auth->username(), $this->auth->id()),
        ]);
    }
}
