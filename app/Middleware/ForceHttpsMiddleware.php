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

        $base = $this->secureBaseUrl();

        // With no configured URL there is nothing trustworthy to redirect to.
        // The Host header would be the obvious substitute and is exactly the
        // wrong one: it is supplied by the caller, so building a redirect from
        // it hands an attacker the destination. Passing the request through
        // leaves it on plain HTTP, which is why it is recorded.
        if ($base === null) {
            $this->security->record(
                'configuration',
                'HTTPS is enforced but APP_URL is not set, so the request could not be redirected.',
                ['path' => $request->path()],
                'none',
                'high'
            );

            return $next($request);
        }

        // 302, not 301: this destination follows from configuration that
        // changes between deployments, and a permanent redirect is cached by
        // the browser long after the configuration it came from is gone.
        return new RedirectResponse($base . $request->fullUrl(), 302);
    }

    /**
     * The configured base URL, with the scheme forced to https.
     *
     * The scheme is forced rather than trusted because this middleware exists
     * to move a request onto https: taking a base of "http://..." from
     * configuration would send the browser back to the URL just refused, and
     * the browser reports that as ERR_TOO_MANY_REDIRECTS rather than as the
     * misconfiguration it is.
     */
    private function secureBaseUrl(): ?string
    {
        $base = rtrim((string) config('app.url', ''), '/');

        if ($base === '') {
            return null;
        }

        if (str_starts_with($base, 'https://')) {
            return $base;
        }

        if (str_starts_with($base, 'http://')) {
            return 'https://' . substr($base, 7);
        }

        return 'https://' . $base;
    }
}
