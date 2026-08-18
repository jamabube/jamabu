<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Exceptions\InvalidRequestException;
use App\Services\SecurityEventService;
use Closure;

/**
 * Requires a JSON body on endpoints that expect one.
 *
 * Rejecting a wrong content type here means every downstream handler can rely
 * on having received structured input, and a malformed body is refused with a
 * precise message rather than surfacing as a confusing validation failure.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class JsonRequestMiddleware implements MiddlewareInterface
{
    public function __construct(private readonly SecurityEventService $security)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // A body is only expected on methods that carry one.
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS', 'DELETE'], true)) {
            return $next($request);
        }

        $contentType = strtolower($request->header('content-type', '') ?? '');

        if (!str_contains($contentType, 'application/json')) {
            $this->security->record(
                'malformed_request',
                sprintf('A request to %s used content type "%s" where JSON is required.', $request->path(), $contentType),
                ['path' => $request->path(), 'content_type' => $contentType],
                'rejected',
                'low'
            );

            throw InvalidRequestException::unsupportedMediaType($contentType === '' ? '(none)' : $contentType);
        }

        // The body was already decoded when the request was captured; an empty
        // result against a non-empty raw body means it did not parse.
        if (trim($request->rawBody()) !== '' && $request->bodyAll() === []) {
            throw InvalidRequestException::malformedJson('The body did not decode to a JSON object.');
        }

        return $next($request);
    }
}
