<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Exceptions\RateLimitException;
use App\Repositories\RateLimitRepository;
use App\Services\SecurityEventService;
use App\Services\SettingsService;
use Closure;

/**
 * Detects abnormal request volume from a single source.
 *
 * Rate limiting protects one endpoint's budget; flood detection watches the
 * total across every endpoint, which is what catches a source spreading its
 * traffic thinly enough to stay under each individual limit.
 *
 * A detected flood produces a temporary block rather than a per-request
 * refusal, so the offending source stops consuming server resources.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class FloodDetectionMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimitRepository $counters,
        private readonly SecurityEventService $security,
        private readonly SettingsService $settings
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!(bool) config('api.flood.enabled', true)) {
            return $next($request);
        }

        $identity = 'ip:' . $request->ip();

        $blockedFor = $this->counters->blockedFor($identity);
        if ($blockedFor > 0) {
            throw RateLimitException::flood($blockedFor);
        }

        $window    = (int) config('api.flood.window', 60);
        $threshold = $this->settings->getInt('api.flood_threshold', (int) config('api.flood.threshold', 300));

        // The flood counter is separate from the per-route buckets so that a
        // legitimate burst on one endpoint does not exhaust the global budget.
        $hits = $this->counters->hit('flood', $identity, $window);

        if ($threshold > 0 && $hits > $threshold) {
            $minutes = max(1, (int) config('api.flood.block_minutes', 15));

            $this->counters->block(
                'flood',
                $identity,
                $window,
                now()->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s')
            );

            $this->security->record(
                'flood_detected',
                sprintf(
                    'Request flooding detected from %s: %d requests in %d seconds against a threshold of %d. The source is blocked for %d minutes.',
                    $request->ip(),
                    $hits,
                    $window,
                    $threshold,
                    $minutes
                ),
                [
                    'ip_address' => $request->ip(),
                    'hits'       => $hits,
                    'threshold'  => $threshold,
                    'window'     => $window,
                    'user_agent' => $request->userAgent(),
                    'path'       => $request->path(),
                ],
                'blocked',
                'critical'
            );

            throw RateLimitException::flood($minutes * 60);
        }

        return $next($request);
    }
}
