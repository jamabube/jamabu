<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Exceptions\AuthenticationException;
use App\Services\AuthenticationService;
use Closure;

/**
 * Requires a valid session, and re-establishes the principal for the request.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class AuthenticateMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly AuthenticationService $authentication)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $this->authentication->resolveFromSession($request);

        if ($user === null) {
            throw AuthenticationException::sessionExpired();
        }

        // An account whose password has expired may reach only the routes that
        // let it set a new one, plus sign-out. Anything else would let an
        // expired credential keep working indefinitely.
        if ($this->authentication->requiresPasswordChange($user) && !$this->isPasswordChangeRoute($request)) {
            if ($request->expectsJson()) {
                throw AuthenticationException::passwordExpired();
            }

            return new \App\Core\Http\RedirectResponse(
                '/profile/password?expired=1',
                302,
                app(\App\Core\Session::class)
            );
        }

        return $next($request);
    }

    /**
     * Routes an account with an expired password is still allowed to reach.
     */
    private function isPasswordChangeRoute(Request $request): bool
    {
        $allowed = [
            '/profile/password',
            '/logout',
            '/api/v1/password/change',
            '/api/v1/logout',
            '/api/v1/session',
        ];

        return in_array('/' . trim($request->path(), '/'), $allowed, true);
    }
}
