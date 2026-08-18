<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use Closure;

/**
 * Correlates every request with an identifier and records its outcome.
 *
 * The database-backed API telemetry is written after the response has been
 * sent (see Kernel::terminate); this middleware handles the file-channel line,
 * which must exist even when the database is the thing that failed.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class RequestLoggingMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // The identifier is echoed back so a user reporting a problem can quote
        // it and an administrator can find the exact request in the logs.
        $response->setHeader((string) config('api.headers.request_id', 'X-Request-Id'), $request->requestId());

        // Successful page views are not worth a line each; failures and API
        // traffic are.
        $shouldLog = $request->isApiRequest() || $response->status() >= 400;

        if ($shouldLog) {
            $context = [
                'method'      => $request->method(),
                'path'        => $request->path(),
                'status'      => $response->status(),
                'duration_ms' => $request->elapsedMs(),
                'ip'          => $request->ip(),
                'request_id'  => $request->requestId(),
            ];

            $channel = $request->isApiRequest() ? 'api' : 'application';

            $response->status() >= 500
                ? logger()->channel($channel)->error('Request failed', $context)
                : logger()->channel($channel)->info('Request completed', $context);
        }

        return $response;
    }
}
