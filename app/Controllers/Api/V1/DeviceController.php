<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\OperatorSessionRepository;
use App\Services\DeviceService;
use App\Services\ErrorLogService;
use App\Services\FingerprintService;

/**
 * The ESP32 device endpoints.
 *
 * Every route here has already passed device authentication by the time an
 * action runs, so the device record is available on the request and no action
 * has to consider the possibility of an unauthenticated caller.
 *
 * Responses are shaped for a constrained client: short, flat, and carrying a
 * display message the firmware can put straight on the station screen without
 * having to compose one.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class DeviceController extends Controller
{
    /**
     * POST /api/v1/device/authenticate
     *
     * Confirms the credentials and hands back the station's configuration, so
     * a device that has just booted learns its operating parameters without a
     * second round trip.
     */
    public function authenticate(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $device */
        $device = $request->attribute('device', []);

        return $this->json('Device authenticated.', [
            'device' => [
                'device_id'   => (int) $device['device_id'],
                'device_code' => (string) $device['device_code'],
                'device_name' => (string) $device['device_name'],
                'gate_type'   => (string) $device['gate_type'],
                'location'    => $device['location'],
            ],
            'configuration' => $this->configurationFor($device),
            'server_time'   => now()->format(DATE_ATOM),
        ]);
    }

    /**
     * GET /api/v1/device/configuration
     *
     * The operating parameters a station needs. Serving these from the server
     * means an administrator can retune a debounce window or a heartbeat
     * interval without reflashing hardware.
     */
    public function configuration(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $device */
        $device = $request->attribute('device', []);

        return $this->json('Configuration retrieved.', [
            'configuration' => $this->configurationFor($device),
            'server_time'   => now()->format(DATE_ATOM),
        ]);
    }

    /**
     * POST /api/v1/device/heartbeat
     *
     * Health telemetry. The response carries the server clock so the station
     * can correct its own drift, which is what keeps its request timestamps
     * inside the tolerance window.
     */
    public function heartbeat(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $device */
        $device = $request->attribute('device', []);

        $telemetry = $this->validate($request, [
            'firmware_version'  => 'nullable|string|max:20',
            'ip_address'        => 'nullable|ip',
            'signal_strength'   => 'nullable|integer|between:-120,0',
            'free_heap_bytes'   => 'nullable|integer|min:0',
            'heap_total_bytes'  => 'nullable|integer|min:0',
            'memory_usage_pct'  => 'nullable|numeric|between:0,100',
            'cpu_usage_pct'     => 'nullable|numeric|between:0,100',
            'temperature_c'     => 'nullable|numeric|between:-40,125',
            'battery_level_pct' => 'nullable|integer|between:0,100',
            'uptime_seconds'    => 'nullable|integer|min:0',
            'queued_requests'   => 'nullable|integer|min:0',
            'last_scan_at'      => 'nullable|date',
            'status'            => 'nullable|string|max:30',
        ]);

        $telemetry['ip_address'] ??= $request->ip();

        $this->service(DeviceService::class)->recordHeartbeat((int) $device['device_id'], $telemetry);

        $operator = $this->service(OperatorSessionRepository::class)
            ->activeForDevice((int) $device['device_id']);

        return $this->json('Heartbeat received.', [
            'server_time'         => now()->format(DATE_ATOM),
            'heartbeat_interval'  => (int) $device['heartbeat_interval'],
            'monitoring_active'   => $operator !== null,
            'operator'            => $operator === null ? null : [
                'name'       => (string) $operator['full_name'],
                'expires_at' => (string) $operator['expires_at'],
            ],
            // A station holding queued transactions is told to send them, so
            // the decision about when to drain the queue stays on the server.
            'flush_queue'         => (int) ($telemetry['queued_requests'] ?? 0) > 0,
        ]);
    }

    /**
     * POST /api/v1/device/status
     *
     * A state change the firmware wants recorded outside the heartbeat cycle,
     * such as a peripheral failing to initialise.
     */
    public function status(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $device */
        $device = $request->attribute('device', []);

        $payload = $this->validate($request, [
            'status'  => 'required|string|max:30',
            'detail'  => 'nullable|string|max:500',
        ]);

        $this->service(DeviceService::class)->recordHeartbeat((int) $device['device_id'], [
            'status'     => $payload['status'],
            'ip_address' => $request->ip(),
        ]);

        return $this->json('Status recorded.', ['server_time' => now()->format(DATE_ATOM)]);
    }

    /**
     * POST /api/v1/device/error
     *
     * A fault the station could not resolve locally. Recording it centrally is
     * what lets an administrator see that a reader is failing without having to
     * be standing at the gate.
     */
    public function error(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $device */
        $device = $request->attribute('device', []);

        $payload = $this->validate($request, [
            'code'     => 'required|string|max:60',
            'message'  => 'required|string|max:500',
            'severity' => 'nullable|in:notice,warning,error,critical',
            'context'  => 'nullable|string|max:2000',
        ], [
            'code'    => 'Error code',
            'message' => 'Error message',
        ]);

        $this->service(\App\Repositories\DeviceRepository::class)->recordError((int) $device['device_id']);

        // A device fault is an application error from the system's point of
        // view: it belongs in the same log an administrator already reviews.
        $this->service(ErrorLogService::class)->record(
            exception: new \RuntimeException(sprintf(
                '[%s] %s',
                (string) $payload['code'],
                (string) $payload['message']
            )),
            reference: strtoupper(substr(bin2hex(random_bytes(6)), 0, 12)),
            severity: (string) ($payload['severity'] ?? 'error'),
            module: 'devices',
            userId: null,
            deviceId: (int) $device['device_id'],
            requestId: $request->requestId(),
            path: $request->path(),
            ipAddress: $request->ip()
        );

        logger()->channel('device')->error('Device reported a fault', [
            'device_code' => (string) $device['device_code'],
            'code'        => $payload['code'],
            'message'     => $payload['message'],
            'context'     => $payload['context'] ?? null,
        ]);

        return $this->json('The fault was recorded.');
    }

    /**
     * POST /api/v1/device/fingerprint/verify
     *
     * The sensor reports the outcome of a verification it performed. On success
     * for an eligible operator this opens the shift that puts the station into
     * monitoring mode.
     */
    public function verifyFingerprint(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $device */
        $device = $request->attribute('device', []);

        $payload = $this->validate($request, [
            'matched'     => 'required|boolean',
            'sensor_slot' => 'nullable|integer|min:0|max:65535',
            'match_score' => 'nullable|integer|min:0|max:1000',
            'purpose'     => 'nullable|in:operator_login,driver_verification,enrolment_check',
        ]);

        $result = $this->service(FingerprintService::class)->recordVerification(
            deviceId: (int) $device['device_id'],
            deviceCode: (string) $device['device_code'],
            sensorSlot: isset($payload['sensor_slot']) ? (int) $payload['sensor_slot'] : null,
            matched: (bool) $payload['matched'],
            matchScore: isset($payload['match_score']) ? (int) $payload['match_score'] : null,
            purpose: (string) ($payload['purpose'] ?? 'operator_login')
        );

        // A refused verification is a normal operating outcome, not a server
        // error, so it answers 200 with granted=false rather than a 4xx the
        // firmware would have to special-case.
        return $this->json($result['message'], [
            'verified'            => $result['successful'],
            'operator'            => $result['user'] === null ? null : [
                'user_id'   => (int) $result['user']['user_id'],
                'name'      => (string) $result['user']['full_name'],
                'role'      => (string) $result['user']['role_name'],
            ],
            'monitoring_active'   => $result['operator_session_id'] !== null,
            'operator_session_id' => $result['operator_session_id'],
            'server_time'         => now()->format(DATE_ATOM),
        ]);
    }

    /**
     * POST /api/v1/device/fingerprint/signout
     *
     * Ends the shift, taking the station out of monitoring mode.
     */
    public function signOutOperator(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $device */
        $device = $request->attribute('device', []);

        $this->service(FingerprintService::class)->signOutOperator((int) $device['device_id']);

        return $this->json('The operator was signed out. Monitoring mode is inactive.', [
            'monitoring_active' => false,
        ]);
    }

    /**
     * POST /api/v1/device/fingerprint/sync
     *
     * Reconciles the slots a sensor reports holding against the server's
     * register, so a divergence is surfaced rather than discovered at the gate.
     */
    public function synchroniseFingerprints(Request $request): JsonResponse
    {
        /** @var array<string,mixed> $device */
        $device = $request->attribute('device', []);

        $slots = array_values(array_filter(
            array_map(intval(...), $request->array('slots')),
            static fn (int $slot): bool => $slot > 0
        ));

        $result = $this->service(FingerprintService::class)->synchronise(
            (int) $device['device_id'],
            $slots,
            null
        );

        return $this->json('Synchronisation complete.', $result);
    }

    /**
     * The configuration payload handed to a station.
     *
     * @param array<string,mixed> $device
     *
     * @return array<string,mixed>
     */
    private function configurationFor(array $device): array
    {
        return [
            'heartbeat_interval'   => (int) $device['heartbeat_interval'],
            'scan_debounce'        => (int) config('api.device.scan_debounce', 5),
            'gate_type'            => (string) $device['gate_type'],
            'require_operator'     => (bool) config('monitoring.rules.require_operator_authentication', true),
            'operator_session_minutes' => (int) config('monitoring.rules.operator_session_minutes', 60),
            'timestamp_tolerance'  => (int) config('api.device.timestamp_tolerance', 120),
            'require_signature'    => (bool) config('api.device.require_signature', true),
            'signature_algorithm'  => (string) config('api.device.signature_algorithm', 'sha256'),
            // The queue bounds are served so the firmware does not have to
            // carry a policy decision in a compile-time constant.
            'max_queue_size'       => 64,
            'retry_backoff_seconds'=> [2, 4, 8, 16, 32, 60],
        ];
    }
}
