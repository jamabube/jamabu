<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Services\SystemHealthService;

/**
 * System health endpoints.
 *
 * The liveness probe is deliberately unauthenticated and deliberately thin: it
 * reports whether the application can serve and reach its database, and
 * nothing else. Anything more would be a reconnaissance endpoint left open.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class HealthController extends Controller
{
    /**
     * GET /api/v1/health
     */
    public function liveness(Request $request): JsonResponse
    {
        $liveness = $this->service(SystemHealthService::class)->liveness();

        // A degraded system answers 503 so an external monitor notices without
        // having to parse the body.
        $status = $liveness['status'] === 'ok' ? 200 : 503;

        return $this->json('System liveness reported.', $liveness, $status);
    }

    /**
     * GET /api/v1/health/report
     */
    public function report(Request $request): JsonResponse
    {
        return $this->json('System health report generated.', $this->service(SystemHealthService::class)->report());
    }

    /**
     * GET /api/v1/health/environment
     */
    public function environment(Request $request): JsonResponse
    {
        return $this->json('Environment details retrieved.', $this->service(SystemHealthService::class)->environment());
    }
}
