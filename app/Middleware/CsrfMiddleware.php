<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\CsrfGuard;
use App\Exceptions\CsrfTokenException;
use App\Services\SecurityEventService;
use Closure;

/**
 * Verifies the CSRF token on every state-changing browser request.
 *
 * Device endpoints are exempt because they are not cookie-authenticated: an
 * ESP32 presents an API key and a signature, so there is no ambient credential
 * for a third-party site to abuse.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class CsrfMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly CsrfGuard $csrf,
        private readonly SecurityEventService $security
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!(bool) config('security.csrf.enabled', true)) {
            return $next($request);
        }

        /** @var list<string> $safeMethods */
        $safeMethods = (array) config('security.csrf.safe_methods', ['GET', 'HEAD', 'OPTIONS']);

        if (in_array($request->method(), $safeMethods, true)) {
            return $next($request);
        }

        if ($this->csrf->verify($this->extractToken($request))) {
            return $next($request);
        }

        $this->security->record(
            'csrf_token_invalid',
            sprintf('A %s request to %s arrived without a valid security token.', $request->method(), $request->path()),
            ['path' => $request->path(), 'method' => $request->method(), 'referer' => $request->header('referer')],
            'rejected'
        );

        throw new CsrfTokenException();
    }

    /**
     * Read the token from the form field or the header.
     *
     * The header form is what lets an AJAX request carry the token without
     * every request body needing an extra field.
     */
    private function extractToken(Request $request): ?string
    {
        $fieldName  = (string) config('security.csrf.token_name', '_csrf_token');
        $headerName = (string) config('security.csrf.header_name', 'X-CSRF-Token');

        $fromBody = $request->input($fieldName);

        if (is_string($fromBody) && $fromBody !== '') {
            return $fromBody;
        }

        return $request->header($headerName);
    }
}
