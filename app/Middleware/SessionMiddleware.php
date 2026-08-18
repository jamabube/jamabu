<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Session;
use Closure;

/**
 * Starts the session for browser-facing routes.
 *
 * Device endpoints never reach this: they are stateless and must not be issued
 * a session cookie, which would be an unnecessary ambient credential.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class SessionMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly Session $session)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $this->session->start(
            (array) config('session', []),
            $request->isSecure()
        );

        return $next($request);
    }
}
