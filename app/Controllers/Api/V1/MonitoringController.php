<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\DTO\ScanRequest;
use App\Repositories\AccessDenialRepository;
use App\Repositories\AccessLogRepository;
use App\Repositories\DeviceRepository;
use App\Services\AccessMonitoringService;

/**
 * Supervisory endpoints over the monitoring record.
 *
 * Everything here writes to, or reads around, the access log without a station
 * being involved: a supervisor closing a visit whose exit scan never arrived,
 * an annotation explaining an anomaly, or the refused-scan register.
 *
 * A monitoring record is never edited in place and never deleted. Corrections
 * are additive — a forced close and an annotation both leave the original
 * entry intact and name the person who made the change.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class MonitoringController extends Controller
{
    /**
     * POST /api/v1/monitoring/{id}/force-close
     *
     * For the visit whose exit was never recorded — a failed reader, a vehicle
     * that left through the service gate. Without this the vehicle stays
     * "inside" forever and can never enter again, because the one-open-visit
     * rule refuses it.
     */
    public function forceClose(Request $request): JsonResponse
    {
        $accessLogId = $request->routeInt('id');

        $payload = $this->validate($request, [
            'reason'    => 'required|string|min:5|max:255',
            'exit_time' => 'nullable|date',
        ], [
            'reason'    => 'Reason',
            'exit_time' => 'Exit time',
        ]);

        $this->service(AccessMonitoringService::class)->forceClose(
            $accessLogId,
            (int) $this->auth->id(),
            (string) $payload['reason'],
            isset($payload['exit_time']) && is_string($payload['exit_time']) ? $payload['exit_time'] : null
        );

        return $this->json('The visit was closed and the reason recorded.', [
            'access_log_id' => $accessLogId,
        ]);
    }

    /**
     * POST /api/v1/monitoring/{id}/annotate
     */
    public function annotate(Request $request): JsonResponse
    {
        $accessLogId = $request->routeInt('id');

        $payload = $this->validate($request, [
            'annotation' => 'required|string|min:3|max:500',
        ], [
            'annotation' => 'Annotation',
        ]);

        $this->service(AccessMonitoringService::class)->annotate(
            $accessLogId,
            (string) $payload['annotation'],
            (int) $this->auth->id()
        );

        return $this->json('The annotation was added to the record.', [
            'access_log_id' => $accessLogId,
        ]);
    }

    /**
     * POST /api/v1/monitoring/manual
     *
     * A movement recorded by hand because a station is out of service. It runs
     * through exactly the same decision engine as a scan — the same refusals
     * apply — so a manual record cannot be used to wave a revoked tag through.
     * The only difference is that the signed-in supervisor stands in for the
     * station's on-duty operator, and the record is marked manual.
     */
    public function manual(Request $request): JsonResponse
    {
        $payload = $this->validate($request, [
            'device_id'   => 'required|integer|exists:devices,device_id',
            'rfid_uid'    => 'required|rfid_uid',
            'access_type' => 'required|in:entry,exit',
            'occurred_at' => 'nullable|date',
            'remarks'     => 'required|string|min:5|max:500',
        ], [
            'device_id'   => 'Station',
            'rfid_uid'    => 'RFID UID',
            'access_type' => 'Movement',
            'occurred_at' => 'Time of movement',
            'remarks'     => 'Reason for the manual record',
        ]);

        $device = $this->service(DeviceRepository::class)->findWithStatus((int) $payload['device_id']);

        if ($device === null) {
            return $this->failure('NOT_FOUND', 'That station does not exist.', 404);
        }

        $scan = ScanRequest::manual(
            (string) $payload['rfid_uid'],
            (string) $payload['access_type'],
            $device,
            (int) $this->auth->id(),
            isset($payload['occurred_at']) && is_string($payload['occurred_at']) ? $payload['occurred_at'] : null,
            $request->requestId(),
            $request->ip(),
            (string) $payload['remarks']
        );

        $decision = $this->service(AccessMonitoringService::class)->process($scan, $device);

        return $this->json($decision->message, $decision->toArray());
    }

    /**
     * GET /api/v1/monitoring/denials
     */
    public function denials(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'occurred_at');
        $paginator = $this->service(AccessDenialRepository::class)->paginate([
            'search'         => $request->string('search'),
            'device_id'      => $request->string('device_id'),
            'reason_code'    => $request->string('reason_code'),
            'attempted_type' => $request->string('attempted_type'),
            'vehicle_id'     => $request->string('vehicle_id'),
            'date_from'      => $request->string('date_from'),
            'date_to'        => $request->string('date_to'),
        ], $options);

        return $this->paginated('Refused scans retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/monitoring/denials/breakdown
     */
    public function denialBreakdown(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $denials = $this->service(AccessDenialRepository::class);

        return $this->json('Refusal breakdown retrieved.', [
            'date_from'      => $from,
            'date_to'        => $to,
            'total'          => $denials->countBetween($from . ' 00:00:00', $to . ' 23:59:59'),
            'by_reason'      => $denials->reasonBreakdown($from . ' 00:00:00', $to . ' 23:59:59'),
            'rejection_rate' => $denials->rejectionRate($from . ' 00:00:00', $to . ' 23:59:59'),
        ]);
    }

    /**
     * GET /api/v1/monitoring/statistics
     */
    public function statistics(Request $request): JsonResponse
    {
        [$from, $to] = $this->range($request);

        $logs = $this->service(AccessLogRepository::class);

        return $this->json('Monitoring statistics retrieved.', [
            'date_from'        => $from,
            'date_to'          => $to,
            'analytics'        => $logs->analytics($from . ' 00:00:00', $to . ' 23:59:59'),
            'daily'            => $logs->dailySummary($from, $to),
            'hourly'           => $logs->hourlyBreakdown($to),
            'most_active'      => $logs->mostActiveVehicles($from . ' 00:00:00', $to . ' 23:59:59'),
            'device_activity'  => $logs->deviceActivity($from . ' 00:00:00', $to . ' 23:59:59'),
            'currently_inside' => $logs->countInside(),
        ]);
    }

    /**
     * The reporting window, defaulting to the last thirty days.
     *
     * @return array{0:string,1:string}
     */
    private function range(Request $request): array
    {
        $to   = $request->string('date_to', now()->format('Y-m-d'));
        $from = $request->string('date_from', now()->modify('-29 days')->format('Y-m-d'));

        return [$from, $to];
    }
}
