<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\DeviceAuthenticationService;
use Closure;

/**
 * Authenticates the ESP32 device behind a request.
 *
 * Applied to every device endpoint. A request that does not pass never reaches
 * a controller, so no business logic anywhere has to consider the possibility
 * of an unauthenticated device.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class DeviceAuthenticationMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly DeviceAuthenticationService $devices)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Throws a DeviceAuthenticationException on any failure, which the
        // error handler renders as the standard JSON envelope with the
        // appropriate status.
        $this->devices->authenticate($request);

        return $next($request);
    }
}
