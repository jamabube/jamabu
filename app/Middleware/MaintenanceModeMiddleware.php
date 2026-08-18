<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\AuthGuard;
use App\Exceptions\MaintenanceModeException;
use Closure;

/**
 * Holds the system closed while maintenance is in progress.
 *
 * The administrator performing the maintenance must still be able to reach the
 * system, otherwise enabling the mode would lock out the only person who can
 * turn it off. Device endpoints are also exempt: a station that cannot report
 * a movement during a maintenance window would silently lose monitoring data.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class MaintenanceModeMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly AuthGuard $auth
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!(bool) config('app.maintenance.enabled', false)) {
            return $next($request);
        }

        // Device traffic continues so no vehicle movement goes unrecorded.
        if ($this->isDeviceEndpoint($request)) {
            return $next($request);
        }

        // Sign-in must stay reachable, or the bypass permission is unusable.
        if ($this->isAuthenticationEndpoint($request)) {
            return $next($request);
        }

        if ($this->auth->can('system.maintenance')) {
            $response = $next($request);

            // A banner keeps the fact visible to whoever is working inside it.
            return $response->setHeader('X-Maintenance-Mode', 'active');
        }

        throw new MaintenanceModeException(
            (string) config('app.maintenance.message', 'The system is undergoing scheduled maintenance.')
        );
    }

    private function isDeviceEndpoint(Request $request): bool
    {
        return str_starts_with($request->path(), '/api/v1/device/')
            || str_starts_with($request->path(), '/api/v1/access/');
    }

    private function isAuthenticationEndpoint(Request $request): bool
    {
        return in_array('/' . trim($request->path(), '/'), ['/login', '/logout', '/api/v1/login', '/api/v1/logout'], true);
    }
}
