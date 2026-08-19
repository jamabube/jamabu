<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Services\DashboardService;
use App\Services\SystemHealthService;

/**
 * Dashboard data endpoints.
 *
 * The dashboard is the screen the guardhouse leaves open all day, so these
 * endpoints are split by cost: the full assembly runs once on load, and the
 * poll endpoint returns only the parts that actually change between refreshes.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class DashboardController extends Controller
{
    /**
     * GET /api/v1/dashboard
     */
    public function index(Request $request): JsonResponse
    {
        return $this->json('Dashboard data retrieved.', $this->service(DashboardService::class)->assemble());
    }

    /**
     * GET /api/v1/dashboard/poll
     *
     * The station passes the highest access-log identifier it has already
     * rendered so the reply carries only what is new.
     */
    public function poll(Request $request): JsonResponse
    {
        $payload = $this->service(DashboardService::class)->poll($request->integer('since_id', 0));

        return $this->json('Dashboard refreshed.', $payload, 200, [
            'poll_after' => (int) config('monitoring.live.poll_interval_seconds', 5),
        ]);
    }

    /**
     * GET /api/v1/dashboard/cards
     */
    public function cards(Request $request): JsonResponse
    {
        return $this->json('Summary cards retrieved.', $this->service(DashboardService::class)->summaryCards());
    }

    /**
     * GET /api/v1/dashboard/charts
     */
    public function charts(Request $request): JsonResponse
    {
        return $this->json('Chart data retrieved.', $this->service(DashboardService::class)->charts());
    }

    /**
     * GET /api/v1/dashboard/devices
     */
    public function devices(Request $request): JsonResponse
    {
        return $this->json('Device status retrieved.', $this->service(DashboardService::class)->deviceStatus());
    }

    /**
     * GET /api/v1/dashboard/alerts
     */
    public function alerts(Request $request): JsonResponse
    {
        $limit = min(50, max(1, $request->integer('limit', 6)));

        return $this->json('Security alerts retrieved.', $this->service(DashboardService::class)->securityAlerts($limit));
    }

    /**
     * GET /api/v1/dashboard/overstaying
     */
    public function overstaying(Request $request): JsonResponse
    {
        return $this->json('Overstaying vehicles retrieved.', $this->service(DashboardService::class)->overstaying());
    }

    /**
     * GET /api/v1/dashboard/health
     *
     * A condensed system state for the header indicator. The full report lives
     * on the system health endpoint, which is administrator-only.
     */
    public function health(Request $request): JsonResponse
    {
        return $this->json('System state retrieved.', $this->service(SystemHealthService::class)->liveness());
    }
}
