<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\RedirectResponse;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Responses\ApiResponse;
use App\Services\SecurityEventService;
use Closure;

/**
 * Rejects unencrypted requests when HTTPS is enforced.
 *
 * A browser is redirected so an operator who typed the wrong scheme still
 * reaches the system; an API client is refused outright, because silently
 * redirecting a device request would let its credentials travel in clear text
 * before the redirect is followed.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class ForceHttpsMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SecurityEventService $security)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!(bool) config('security.transport.force_https', true) || $request->isSecure()) {
            return $next($request);
        }

        if ($request->isApiRequest()) {
            $this->security->record(
                'malformed_request',
                'An API request arrived over plain HTTP while encrypted transport is required.',
                ['path' => $request->path(), 'method' => $request->method()],
                'rejected',
                'high'
            );

            return ApiResponse::error(
                'HTTPS_REQUIRED',
                'This endpoint requires an encrypted connection.',
                403
            );
        }

        $target = rtrim((string) config('app.url', ''), '/') . $request->fullUrl();

        return new RedirectResponse($target, 301);
    }
}
