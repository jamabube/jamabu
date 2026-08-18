<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\AuthGuard;
use App\Exceptions\AuthorizationException;
use App\Services\AuditService;
use App\Services\SecurityEventService;
use Closure;

/**
 * Enforces the permission a route declares.
 *
 * Because the requirement lives on the route rather than inside the action, a
 * new endpoint cannot be added without a permission decision being made about
 * it — and the security-audit command can verify that every route has one.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class AuthorizeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthGuard $auth,
        private readonly SecurityEventService $security,
        private readonly AuditService $audit
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $permission = $request->attribute('required_permission');

        // A route with no declared permission is reachable by any
        // authenticated user; that is a deliberate choice made at the route,
        // not an omission tolerated here.
        if (!is_string($permission) || $permission === '') {
            return $next($request);
        }

        if ($this->auth->can($permission)) {
            return $next($request);
        }

        // Every refusal is evidence: an account probing for capabilities it
        // does not hold is exactly the pattern worth reviewing later.
        $this->security->unauthorized($permission, $request->path());

        $this->audit->failed('authorization', 'denied', sprintf(
            'Access to %s was refused; "%s" is required.',
            $request->path(),
            $permission
        ));

        throw AuthorizationException::forPermission($permission);
    }
}
