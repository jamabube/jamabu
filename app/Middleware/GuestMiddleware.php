<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\RedirectResponse;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use App\Services\AuthenticationService;
use Closure;

/**
 * Keeps an already-signed-in user away from the sign-in page.
 *
 * Without this, submitting the login form while holding a valid session would
 * discard it and issue a new one for no reason.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class GuestMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthenticationService $authentication,
        private readonly Session $session
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->authentication->resolveFromSession($request) !== null) {
            return new RedirectResponse('/', 302, $this->session);
        }

        return $next($request);
    }
}
