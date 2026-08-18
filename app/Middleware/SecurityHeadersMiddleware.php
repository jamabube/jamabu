<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Support\Str;
use Closure;

/**
 * Attaches the HTTP security headers to every response.
 *
 * Runs outermost in the global stack so the headers are present even on an
 * error response produced deeper in the pipeline.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class SecurityHeadersMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        // A per-request nonce lets the strict CSP allow the few inline scripts
        // the interface needs without ever resorting to 'unsafe-inline'.
        $nonce = Str::randomHex(16);
        $request->setAttribute('csp_nonce', $nonce);

        $response = $next($request);

        /** @var array<string,string|null> $headers */
        $headers = (array) config('security.headers', []);

        foreach ($headers as $name => $value) {
            if ($value !== null && $value !== '') {
                $response->setHeader($name, (string) $value);
            }
        }

        // HSTS is only meaningful over TLS, and setting it on a plain HTTP
        // response would be ignored by browsers anyway.
        if ((bool) config('security.transport.hsts.enabled', true) && $request->isSecure()) {
            $directives = ['max-age=' . (int) config('security.transport.hsts.max_age', 31536000)];

            if ((bool) config('security.transport.hsts.include_subdomains', true)) {
                $directives[] = 'includeSubDomains';
            }

            if ((bool) config('security.transport.hsts.preload', false)) {
                $directives[] = 'preload';
            }

            $response->setHeader('Strict-Transport-Security', implode('; ', $directives));
        }

        if ((bool) config('security.csp.enabled', true)) {
            $header = (bool) config('security.csp.report_only', false)
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            $response->setHeader($header, $this->buildPolicy($nonce));
        }

        // The server's identity is not something a client needs.
        $response->removeHeader('X-Powered-By');
        $response->removeHeader('Server');

        return $response;
    }

    /**
     * Assemble the Content-Security-Policy header.
     */
    private function buildPolicy(string $nonce): string
    {
        /** @var array<string,list<string>> $directives */
        $directives = (array) config('security.csp.directives', []);
        $parts      = [];

        foreach ($directives as $directive => $sources) {
            $values = (array) $sources;

            if ($directive === 'script-src') {
                $values[] = "'nonce-" . $nonce . "'";
            }

            // Google Fonts and other external hosts are deliberately absent:
            // this system runs on an isolated LAN and must not depend on, or
            // leak requests to, anything outside it.
            $parts[] = $directive . ' ' . implode(' ', array_unique($values));
        }

        return implode('; ', $parts);
    }
}
