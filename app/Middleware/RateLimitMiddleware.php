<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\AuthGuard;
use App\Exceptions\RateLimitException;
use App\Repositories\RateLimitRepository;
use App\Services\SecurityEventService;
use Closure;

/**
 * Applies the configured request budget to a route.
 *
 * The identity is the most specific thing known about the caller: the device
 * code for a station, the user id for a signed-in operator, otherwise the
 * source address. Using the device code rather than the address matters on a
 * LAN, where several stations can share one NAT address.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class RateLimitMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly RateLimitRepository $counters,
        private readonly SecurityEventService $security,
        private readonly AuthGuard $auth
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (!(bool) config('api.rate_limit.enabled', true)) {
            return $next($request);
        }

        $bucket = $this->bucket($request);
        [$limit, $window] = $this->budgetFor($bucket);

        if ($limit <= 0) {
            return $next($request);
        }

        $identity = $this->identity($request);

        // An identity already under a block is refused before its request is
        // even counted, so a blocked source cannot keep the window alive.
        $blockedFor = $this->counters->blockedFor($identity);
        if ($blockedFor > 0) {
            throw RateLimitException::flood($blockedFor);
        }

        $hits = $this->counters->hit($bucket, $identity, $window);

        if ($hits > $limit) {
            $retryAfter = $this->secondsRemaining($window);

            $this->security->record(
                'rate_limit',
                sprintf(
                    'The "%s" rate limit was exceeded by %s (%d requests in %d seconds; the limit is %d).',
                    $bucket,
                    $identity,
                    $hits,
                    $window,
                    $limit
                ),
                ['bucket' => $bucket, 'identity' => $identity, 'hits' => $hits, 'limit' => $limit],
                'rate_limited'
            );

            throw new RateLimitException($retryAfter);
        }

        $response = $next($request);

        // The remaining budget is advertised so a well-behaved client can pace
        // itself instead of discovering the limit by hitting it.
        return $response
            ->setHeader('X-RateLimit-Limit', (string) $limit)
            ->setHeader('X-RateLimit-Remaining', (string) max(0, $limit - $hits))
            ->setHeader('X-RateLimit-Reset', (string) $this->secondsRemaining($window));
    }

    /**
     * The bucket this route draws from.
     */
    private function bucket(Request $request): string
    {
        $declared = $request->attribute('rate_limit_bucket');

        return is_string($declared) && $declared !== '' ? $declared : 'default';
    }

    /**
     * The limit and window for a bucket, honouring runtime settings.
     *
     * @return array{0:int,1:int}
     */
    private function budgetFor(string $bucket): array
    {
        /** @var array<string,array{limit:int,window:int}> $buckets */
        $buckets = (array) config('api.rate_limit.buckets', []);

        if (isset($buckets[$bucket])) {
            return [(int) $buckets[$bucket]['limit'], (int) $buckets[$bucket]['window']];
        }

        return [
            (int) config('api.rate_limit.default.limit', 120),
            (int) config('api.rate_limit.default.window', 60),
        ];
    }

    /**
     * Identify the caller as specifically as possible.
     */
    private function identity(Request $request): string
    {
        $deviceCode = $this->auth->deviceCode()
            ?? $request->header((string) config('api.headers.device_id', 'X-Device-Id'));

        if (is_string($deviceCode) && $deviceCode !== '') {
            return 'device:' . mb_substr($deviceCode, 0, 40);
        }

        $userId = $this->auth->id();

        if ($userId !== null) {
            return 'user:' . $userId;
        }

        // Before sign-in there is no principal, so the address is all there is
        // — which is exactly the case the login bucket needs to throttle.
        return 'ip:' . $request->ip();
    }

    /**
     * Seconds left in the current fixed window.
     */
    private function secondsRemaining(int $window): int
    {
        $window = max(1, $window);

        return $window - (time() % $window);
    }
}
