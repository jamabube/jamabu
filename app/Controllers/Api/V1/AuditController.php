<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\AuditLogRepository;

/**
 * Audit trail endpoints.
 *
 * Read-only by design and by database trigger: the audit table accepts inserts
 * and nothing else. There is deliberately no endpoint here that edits or
 * removes an entry, because a trail that can be corrected is not a trail.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class AuditController extends Controller
{
    /**
     * GET /api/v1/audit
     */
    public function index(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'created_at');
        $paginator = $this->service(AuditLogRepository::class)->paginate([
            'search'      => $request->string('search'),
            'user_id'     => $request->string('user_id'),
            'device_id'   => $request->string('device_id'),
            'module'      => $request->string('module'),
            'action'      => $request->string('action'),
            'status'      => $request->string('status'),
            'record_type' => $request->string('record_type'),
            'ip_address'  => $request->string('ip_address'),
            'date_from'   => $request->string('date_from'),
            'date_to'     => $request->string('date_to'),
        ], $options);

        return $this->paginated('Audit entries retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/audit/filters
     *
     * The distinct values present in the trail, so the filter dropdowns offer
     * what actually exists rather than a hardcoded guess.
     */
    public function filters(Request $request): JsonResponse
    {
        return $this->json('Audit filter options retrieved.', $this->service(AuditLogRepository::class)->filterOptions());
    }

    /**
     * GET /api/v1/audit/record/{type}/{id}
     *
     * Everything that has ever happened to one record — the history panel on a
     * vehicle, a user, a device.
     */
    public function forRecord(Request $request): JsonResponse
    {
        $recordType = (string) $request->route('type', '');
        $recordId   = $request->routeInt('id');

        return $this->json('Record history retrieved.', $this->service(AuditLogRepository::class)->forRecord(
            $recordType,
            $recordId,
            min(500, max(1, $request->integer('limit', 100)))
        ));
    }

    /**
     * GET /api/v1/audit/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $to   = $request->string('date_to', now()->format('Y-m-d')) . ' 23:59:59';
        $from = $request->string('date_from', now()->modify('-29 days')->format('Y-m-d')) . ' 00:00:00';

        $repository = $this->service(AuditLogRepository::class);

        return $this->json('Audit summary retrieved.', [
            'date_from'   => $from,
            'date_to'     => $to,
            'by_action'   => $repository->summaryByAction($from, $to),
            'today'       => $repository->countSince(now()->format('Y-m-d 00:00:00')),
            'recent'      => $repository->recent(),
        ]);
    }
}
