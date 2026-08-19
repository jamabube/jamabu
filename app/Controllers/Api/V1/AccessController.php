<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\DTO\ScanRequest;
use App\Repositories\AccessLogRepository;
use App\Services\AccessMonitoringService;

/**
 * Vehicle access endpoints.
 *
 * The two that matter are entry and exit: they are the only routes in the
 * system that create an official monitoring record, and they run on every
 * vehicle movement through the park.
 *
 * A refused scan answers 200 with granted=false rather than a 4xx. That is
 * deliberate: a vehicle that is not authorised is a normal operating outcome,
 * not a failure of the request, and the firmware displays the message either
 * way. Genuine failures — a bad credential, a malformed body — still answer
 * with the appropriate error status.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class AccessController extends Controller
{
    /**
     * POST /api/v1/access/entry
     */
    public function entry(Request $request): JsonResponse
    {
        return $this->processScan($request, 'entry');
    }

    /**
     * POST /api/v1/access/exit
     */
    public function exit(Request $request): JsonResponse
    {
        return $this->processScan($request, 'exit');
    }

    /**
     * POST /api/v1/access/scan
     *
     * The station reports a read and lets the server decide which way the
     * vehicle is going, based on the gate's role and whether the vehicle is
     * currently inside. A single-lane gate uses this rather than having the
     * firmware guess.
     */
    public function scan(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $device */
        $device = $request->attribute('device', []);

        $declared = $request->string('action');

        if (in_array($declared, ['entry', 'exit'], true)) {
            return $this->processScan($request, $declared);
        }

        return $this->processScan($request, $this->inferDirection($request, $device));
    }

    /**
     * Validate the payload, run the decision engine, shape the reply.
     */
    private function processScan(Request $request, string $accessType): JsonResponse
    {
        /** @var array<string,mixed> $device */
        $device = $request->attribute('device', []);

        $payload = $this->validate($request, [
            'rfid_uid'            => 'required|rfid_uid',
            'scanned_at'          => 'nullable|date',
            'verification_method' => 'nullable|in:rfid,rfid+fingerprint,manual,visitor_card',
            'remarks'             => 'nullable|string|max:500',
        ], [
            'rfid_uid' => 'RFID UID',
        ]);

        $scan = ScanRequest::fromPayload(
            $payload,
            $device,
            $accessType,
            $request->requestId(),
            $request->ip()
        );

        $decision = $this->service(AccessMonitoringService::class)->process($scan, $device);

        return $this->json($decision->message, $decision->toArray());
    }

    /**
     * Work out which direction a scan represents.
     *
     * A gate restricted to one role answers immediately. On a shared lane the
     * vehicle's current presence decides: inside means it is leaving.
     *
     * @param array<string,mixed> $device
     */
    private function inferDirection(Request $request, array $device): string
    {
        $gateType = (string) ($device['gate_type'] ?? 'both');

        if ($gateType === 'entry' || $gateType === 'exit') {
            return $gateType;
        }

        $uid     = \App\Core\Support\Str::normaliseUid($request->string('rfid_uid'));
        $vehicle = $this->service(\App\Repositories\VehicleRepository::class)->findByRfidUid($uid);

        if ($vehicle === null || $vehicle['vehicle_id'] === null) {
            // An unknown tag has no presence to consult; treating it as an
            // entry attempt produces the clearer refusal message.
            return 'entry';
        }

        $open = $this->service(AccessLogRepository::class)
            ->openVisitForVehicle((int) $vehicle['vehicle_id']);

        return $open === null ? 'entry' : 'exit';
    }

    // ------------------------------------------------------------------
    // Read endpoints, used by the dashboard rather than by a station
    // ------------------------------------------------------------------

    /**
     * GET /api/v1/access/live
     */
    public function live(Request $request): JsonResponse
    {
        $records = $this->service(AccessLogRepository::class)->liveFeed(
            min(100, max(1, $request->integer('limit', 25))),
            $request->integer('since_id', 0)
        );

        return $this->json('Live activity retrieved.', $records, 200, [
            'count'       => count($records),
            'polled_at'   => now()->format(DATE_ATOM),
            'poll_after'  => (int) config('monitoring.live.poll_interval_seconds', 5),
        ]);
    }

    /**
     * GET /api/v1/access/inside
     */
    public function inside(Request $request): JsonResponse
    {
        $records = $this->service(AccessLogRepository::class)->currentlyInside(
            min(500, max(1, $request->integer('limit', 200)))
        );

        return $this->json('Vehicles currently inside retrieved.', $records, 200, [
            'count' => count($records),
        ]);
    }

    /**
     * GET /api/v1/access/history
     */
    public function history(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'entry_time');
        $paginator = $this->service(AccessLogRepository::class)->paginate(
            $this->historyFilters($request),
            $options
        );

        return $this->paginated('Access history retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/access/{id}
     */
    public function show(Request $request): JsonResponse
    {
        $record = $this->service(AccessLogRepository::class)->findInView($request->routeInt('id'));

        if ($record === null) {
            return $this->failure('NOT_FOUND', 'That monitoring record does not exist.', 404);
        }

        return $this->json('Monitoring record retrieved.', $record);
    }

    /**
     * Extract the filters the history endpoint understands.
     *
     * @return array<string,mixed>
     */
    private function historyFilters(Request $request): array
    {
        return [
            'search'            => $request->string('search'),
            'vehicle_id'        => $request->string('vehicle_id'),
            'driver_id'         => $request->string('driver_id'),
            'device_id'         => $request->string('device_id'),
            'entry_operator_id' => $request->string('operator_id'),
            'status'            => $request->string('status'),
            'access_type'       => $request->string('access_type'),
            'vehicle_type'      => $request->string('vehicle_type'),
            'is_visitor'        => $request->string('is_visitor'),
            'plate_number'      => $request->string('plate_number'),
            'date_from'         => $request->string('date_from'),
            'date_to'           => $request->string('date_to'),
            'min_minutes'       => $request->string('min_minutes'),
            'max_minutes'       => $request->string('max_minutes'),
        ];
    }
}
