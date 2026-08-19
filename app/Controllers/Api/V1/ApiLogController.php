<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\ApiRequestLogRepository;

/**
 * API traffic register.
 *
 * Every device call is recorded with its outcome and duration. This is what
 * answers "is the entry station actually talking to us, and how fast?" without
 * having to log into the station itself.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class ApiLogController extends Controller
{
    /**
     * GET /api/v1/api-logs
     */
    public function index(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'created_at');
        $paginator = $this->service(ApiRequestLogRepository::class)->paginate([
            'search'      => $request->string('search'),
            'device_id'   => $request->string('device_id'),
            'user_id'     => $request->string('user_id'),
            'method'      => $request->string('method'),
            'status_code' => $request->string('status_code'),
            'outcome'     => $request->string('outcome'),
            'slow_only'   => $request->boolean('slow_only'),
            'date_from'   => $request->string('date_from'),
            'date_to'     => $request->string('date_to'),
        ], $options);

        return $this->paginated('API requests retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/api-logs/performance
     */
    public function performance(Request $request): JsonResponse
    {
        $hours = min(720, max(1, $request->integer('hours', 24)));
        $since = now()->modify('-' . $hours . ' hours')->format('Y-m-d H:i:s');

        $repository = $this->service(ApiRequestLogRepository::class);

        return $this->json('API performance retrieved.', [
            'since'       => $since,
            'hours'       => $hours,
            'performance' => $repository->performanceSince($since),
            'busiest'     => $repository->busiestEndpoints($since),
        ]);
    }
}
