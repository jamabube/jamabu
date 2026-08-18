<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Connection;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\AuthGuard;
use App\Core\Support\Arr;
use App\Repositories\ApiRequestLogRepository;
use Throwable;

/**
 * Records API request telemetry.
 *
 * Written after the response has been sent, so the measurement never adds to
 * the latency it is measuring.
 *
 * @package App\Services
 * @version 1.0.0
 */
class ApiRequestLogService
{
    public function __construct(
        private readonly ApiRequestLogRepository $repository,
        private readonly AuthGuard $auth,
        private readonly Connection $connection
    ) {
    }

    /**
     * Store the record for a completed request.
     *
     * Called from the kernel's terminate stage.
     */
    public function finalise(Request $request, Response $response): void
    {
        if (!(bool) config('api.logging.log_requests', true)) {
            return;
        }

        // Only API traffic is recorded. Logging every page view would bury the
        // device communication this table exists to make reviewable.
        if (!$request->isApiRequest()) {
            return;
        }

        try {
            $this->repository->create([
                'request_id'     => $request->requestId(),
                'endpoint'       => mb_substr($request->path(), 0, 255),
                'route_name'     => $this->routeName($request),
                'method'         => $request->method(),
                'user_id'        => $this->auth->id(),
                'device_id'      => $this->auth->deviceId(),
                'ip_address'     => $request->ip(),
                'user_agent'     => mb_substr($request->userAgent(), 0, 255),
                'status_code'    => $response->status(),
                'error_code'     => $this->errorCode($response),
                'duration_ms'    => $request->elapsedMs(),
                'query_count'    => $this->connection->queryCount(),
                'request_bytes'  => strlen($request->rawBody()),
                'response_bytes' => strlen($response->content()),
                'payload'        => $this->payload($request),
                'created_at'     => now()->format('Y-m-d H:i:s'),
            ]);

            $threshold = (float) config('api.logging.slow_request_ms', 2000);
            if ($threshold > 0 && $request->elapsedMs() >= $threshold) {
                logger()->channel('performance')->warning('Slow API request', [
                    'endpoint'   => $request->path(),
                    'method'     => $request->method(),
                    'duration_ms'=> $request->elapsedMs(),
                    'queries'    => $this->connection->queryCount(),
                ]);
            }
        } catch (Throwable $e) {
            logger()->channel('api')->warning('API request log could not be stored', [
                'endpoint' => $request->path(),
                'reason'   => $e->getMessage(),
            ]);
        }
    }

    private function routeName(Request $request): ?string
    {
        $name = $request->attribute('route_name');

        return is_string($name) ? mb_substr($name, 0, 80) : null;
    }

    /**
     * Pull the envelope's error_code out of a failed JSON response, which is
     * what makes "show me every INVALID_RFID rejection" a single query.
     */
    private function errorCode(Response $response): ?string
    {
        if ($response->status() < 400 || !$response instanceof JsonResponse) {
            return null;
        }

        $code = $response->payload()['error_code'] ?? null;

        return is_string($code) ? mb_substr($code, 0, 60) : null;
    }

    /**
     * The request body, redacted, when body logging is switched on.
     */
    private function payload(Request $request): ?string
    {
        if (!(bool) config('api.logging.log_request_body', false)) {
            return null;
        }

        $body = $request->all();

        if ($body === []) {
            return null;
        }

        /** @var list<string> $redact */
        $redact = (array) config('api.logging.redact_fields', []);
        $json   = json_encode(Arr::redact($body, $redact), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        if ($json === false) {
            return null;
        }

        // A stored payload must stay bounded; a device that posts an oversized
        // body should not be able to fill the log table with it.
        return mb_substr($json, 0, 4000);
    }
}
