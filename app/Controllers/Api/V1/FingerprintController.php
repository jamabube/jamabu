<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\FingerprintRepository;
use App\Repositories\FingerprintVerificationRepository;
use App\Repositories\OperatorSessionRepository;
use App\Responses\ApiResponse;
use App\Services\FingerprintService;

/**
 * Fingerprint enrolment administration.
 *
 * The sensor keeps the biometric data; this system keeps only a slot number
 * and a one-way checksum of what the sensor reported. Nothing here can
 * reconstruct a fingerprint, which is the point — a stolen database must not
 * be a stolen identity.
 *
 * Verification itself belongs to the device API; these endpoints manage who is
 * enrolled, on which sensor, in which slot.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class FingerprintController extends Controller
{
    /**
     * GET /api/v1/fingerprints
     */
    public function index(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'enrolled_at');
        $paginator = $this->service(FingerprintService::class)->paginate([
            'search'    => $request->string('search'),
            'status'    => $request->string('status'),
            'device_id' => $request->string('device_id'),
            'holder'    => $request->string('holder'),
        ], $options);

        return $this->paginated('Fingerprint enrolments retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/fingerprints/summary
     */
    public function summary(Request $request): JsonResponse
    {
        return $this->json('Fingerprint summary retrieved.', $this->service(FingerprintService::class)->summary());
    }

    /**
     * GET /api/v1/fingerprints/{id}
     */
    public function show(Request $request): JsonResponse
    {
        $templateId = $request->routeInt('id');
        $template   = $this->service(FingerprintRepository::class)->findWithHolder($templateId);

        if ($template === null) {
            return $this->failure('NOT_FOUND', 'That enrolment does not exist.', 404);
        }

        return $this->json('Enrolment retrieved.', [
            'enrolment'     => $template,
            'verifications' => $this->service(FingerprintVerificationRepository::class)->forTemplate($templateId),
        ]);
    }

    /**
     * GET /api/v1/fingerprints/next-slot
     *
     * Tells the enrolment screen which slot the sensor should be told to use,
     * so the operator never has to guess a free position.
     */
    public function nextSlot(Request $request): JsonResponse
    {
        $deviceId = $request->integer('device_id', 0);

        if ($deviceId <= 0) {
            return $this->failure('INVALID_DEVICE', 'A device must be selected.', 422);
        }

        $capacity = (int) config('monitoring.fingerprint.sensor_capacity', 1000);
        $slot     = $this->service(FingerprintRepository::class)->nextAvailableSlot($deviceId, $capacity);

        return $this->json($slot === 0 ? 'This sensor is full.' : 'A free slot is available.', [
            'device_id' => $deviceId,
            'slot'      => $slot === 0 ? null : $slot,
            'capacity'  => $capacity,
        ]);
    }

    /**
     * POST /api/v1/fingerprints
     */
    public function store(Request $request): JsonResponse
    {
        $attributes = $this->validate($request, [
            'device_id'          => 'required|integer|exists:devices,device_id',
            'sensor_slot'        => 'nullable|integer|between:1,4000',
            'finger_label'       => 'nullable|string|max:30',
            'assigned_user_id'   => 'nullable|integer|exists:users,user_id',
            'assigned_driver_id' => 'nullable|integer|exists:drivers,driver_id',
            'checksum'           => 'nullable|string|max:128',
            'quality_score'      => 'nullable|integer|between:0,100',
            'remarks'            => 'nullable|string|max:255',
        ], [
            'device_id'          => 'Sensor',
            'sensor_slot'        => 'Sensor slot',
            'finger_label'       => 'Finger',
            'assigned_user_id'   => 'System user',
            'assigned_driver_id' => 'Driver',
            'quality_score'      => 'Enrolment quality',
        ]);

        $templateId = $this->service(FingerprintService::class)->enrol($attributes, $this->auth->id());

        return ApiResponse::created('The enrolment was recorded.', ['template_id' => $templateId]);
    }

    /**
     * DELETE /api/v1/fingerprints/{id}
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->service(FingerprintService::class)->remove($request->routeInt('id'), $this->auth->id());

        return ApiResponse::deleted('The enrolment was removed. Clear the slot on the sensor as well.');
    }

    /**
     * POST /api/v1/fingerprints/synchronise
     *
     * Reconciles the register against what a sensor reports it is holding.
     * Discrepancies are reported, never resolved automatically: deleting an
     * enrolment because a sensor was briefly unreachable would lock somebody
     * out of their own shift.
     */
    public function synchronise(Request $request): JsonResponse
    {
        $payload = $this->validate($request, [
            'device_id' => 'required|integer|exists:devices,device_id',
            'slots'     => 'required|array',
        ], [
            'device_id' => 'Sensor',
            'slots'     => 'Slots reported by the sensor',
        ]);

        /** @var list<int> $slots */
        $slots = array_values(array_unique(array_map(
            static fn (mixed $slot): int => (int) $slot,
            is_array($payload['slots']) ? $payload['slots'] : []
        )));

        $result = $this->service(FingerprintService::class)->synchronise(
            (int) $payload['device_id'],
            $slots,
            $this->auth->id()
        );

        return $this->json('Synchronisation complete.', $result);
    }

    /**
     * GET /api/v1/fingerprints/verifications
     */
    public function verifications(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'verified_at');
        $paginator = $this->service(FingerprintVerificationRepository::class)->paginate([
            'search'      => $request->string('search'),
            'device_id'   => $request->string('device_id'),
            'template_id' => $request->string('template_id'),
            'user_id'     => $request->string('user_id'),
            'purpose'     => $request->string('purpose'),
            'successful'  => $request->string('successful'),
            'date_from'   => $request->string('date_from'),
            'date_to'     => $request->string('date_to'),
        ], $options);

        return $this->paginated('Verification attempts retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/fingerprints/operators
     *
     * Who is currently signed in at each station.
     */
    public function operatorSessions(Request $request): JsonResponse
    {
        $sessions = $this->service(OperatorSessionRepository::class)->history([
            'device_id' => $request->string('device_id'),
            'user_id'   => $request->string('user_id'),
            'status'    => $request->string('status'),
        ], min(200, max(1, $request->integer('limit', 100))));

        return $this->json('Operator sessions retrieved.', $sessions, 200, [
            'active' => $this->service(OperatorSessionRepository::class)->countActive(),
        ]);
    }

    /**
     * POST /api/v1/fingerprints/operators/{id}/close
     *
     * Ends the duty session at a station from the dashboard, for the case an
     * operator walks away without signing out.
     */
    public function closeOperatorSession(Request $request): JsonResponse
    {
        $deviceId = $request->routeInt('id');

        $this->service(FingerprintService::class)->signOutOperator($deviceId, 'closed_by_supervisor');

        return $this->json('The duty session at that station was ended.', ['device_id' => $deviceId]);
    }
}
