<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Exceptions\AuthenticationException;
use App\Exceptions\ValidationException;
use App\Services\AuthenticationService;
use App\Services\PasswordPolicyService;
use App\Services\UserService;

/**
 * Browser sign-in and password management.
 *
 * The sign-in form posts here rather than to the JSON API so that a browser
 * with JavaScript disabled can still reach the system — a guardhouse
 * workstation that cannot sign in is a gate that cannot open.
 *
 * @package App\Controllers\Web
 * @version 1.0.0
 */
final class AuthController extends Controller
{
    /**
     * GET /login
     */
    public function showLogin(Request $request): Response
    {
        return $this->render('pages/auth/login', [
            'title'     => 'Sign in',
            'intended'  => $request->string('intended'),
            'timed_out' => $request->boolean('timeout'),
        ]);
    }

    /**
     * POST /login
     */
    public function login(Request $request): Response
    {
        try {
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
        } catch (ValidationException $e) {
            return $this->redirect('/login')
                ->withErrors($e->errors())
                ->withInput($request->all());
        } catch (AuthenticationException $e) {
            // The message is deliberately whatever the service chose: it
            // already decides how much to reveal about why a sign-in failed.
            return $this->redirect('/login')
                ->withError($e->getMessage())
                ->withInput($request->only(['username']));
        }

        if ($this->service(AuthenticationService::class)->requiresPasswordChange($user)) {
            return $this->redirect('/profile/password?expired=1')
                ->withWarning('Your password must be changed before you can continue.');
        }

        $intended = $this->safeIntended($request->string('intended'));

        return $this->redirect($intended)->withSuccess(sprintf('Welcome back, %s.', $user->fullName));
    }

    /**
     * POST /logout
     */
    public function logout(Request $request): Response
    {
        $this->service(AuthenticationService::class)->logout();

        return $this->redirect('/login')->withInfo('You have been signed out.');
    }

    /**
     * GET /profile/password
     */
    public function showChangePassword(Request $request): Response
    {
        $policy = $this->service(PasswordPolicyService::class);

        return $this->render('pages/auth/change-password', [
            'title'   => 'Change password',
            'expired' => $request->boolean('expired'),
            'rules'   => [
                'min_length'        => (int) config('security.password.min_length', 12),
                'require_uppercase' => (bool) config('security.password.require_uppercase', true),
                'require_lowercase' => (bool) config('security.password.require_lowercase', true),
                'require_numeric'   => (bool) config('security.password.require_numeric', true),
                'require_special'   => (bool) config('security.password.require_special', true),
                'history_depth'     => (int) config('security.password.history_depth', 5),
            ],
            'suggestion' => $policy->generate(),
        ]);
    }

    /**
     * POST /profile/password
     */
    public function changePassword(Request $request): Response
    {
        try {
            $payload = $this->validate($request, [
                'current_password'          => 'required|string|max:128',
                'new_password'              => 'required|string|max:128|confirmed',
                'new_password_confirmation' => 'required|string|max:128',
            ], [
                'current_password' => 'Current password',
                'new_password'     => 'New password',
            ]);

            $this->service(UserService::class)->changeOwnPassword(
                (int) $this->auth->id(),
                (string) $payload['current_password'],
                (string) $payload['new_password']
            );
        } catch (ValidationException $e) {
            return $this->redirect('/profile/password')->withErrors($e->errors());
        }

        return $this->redirect('/')
            ->withSuccess('Your password was changed. Every other session has been signed out.');
    }

    /**
     * Only a path inside this application is honoured as a post-sign-in
     * destination, so the parameter cannot be used to bounce a user to another
     * site that imitates this one.
     */
    private function safeIntended(string $intended): string
    {
        if ($intended === '' || !str_starts_with($intended, '/') || str_starts_with($intended, '//')) {
            return '/';
        }

        return $intended;
    }
}
