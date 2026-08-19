<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\ApiRequestLogRepository;
use App\Repositories\DeviceHeartbeatRepository;
use App\Repositories\DeviceRepository;
use App\Responses\ApiResponse;
use App\Services\DeviceService;

/**
 * Administration of the monitoring stations.
 *
 * Distinct from the device API: nothing here is called by an ESP32. These are
 * the endpoints an administrator uses to commission a station, watch its
 * health and rotate its credentials.
 *
 * An API key is returned exactly once, at issue and at rotation. Only its hash
 * is stored, so a lost key cannot be recovered — it can only be replaced.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class DeviceManagementController extends Controller
{
    /**
     * GET /api/v1/devices
     */
    public function index(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'device_code', 'ASC');
        $paginator = $this->service(DeviceService::class)->paginate([
            'search'       => $request->string('search'),
            'status'       => $request->string('status'),
            'gate_type'    => $request->string('gate_type'),
            'connectivity' => $request->string('connectivity'),
        ], $options);

        return $this->paginated('Monitoring stations retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/devices/status
     */
    public function status(Request $request): JsonResponse
    {
        return $this->json('Station status retrieved.', $this->service(DeviceService::class)->allWithStatus(), 200, [
            'offline_after' => (int) config('api.device.offline_after', 90),
        ]);
    }

    /**
     * GET /api/v1/devices/summary
     */
    public function summary(Request $request): JsonResponse
    {
        return $this->json('Station summary retrieved.', $this->service(DeviceService::class)->summary());
    }

    /**
     * GET /api/v1/devices/{id}
     */
    public function show(Request $request): JsonResponse
    {
        $device = $this->service(DeviceRepository::class)->findWithStatus($request->routeInt('id'));

        if ($device === null) {
            return $this->failure('NOT_FOUND', 'That monitoring station does not exist.', 404);
        }

        return $this->json('Monitoring station retrieved.', $device);
    }

    /**
     * GET /api/v1/devices/{id}/diagnostics
     */
    public function diagnostics(Request $request): JsonResponse
    {
        $deviceId = $request->routeInt('id');
        $hours    = min(168, max(1, $request->integer('hours', 6)));

        return $this->json('Station diagnostics retrieved.', [
            'diagnostics' => $this->service(DeviceService::class)->diagnostics($deviceId),
            'heartbeats'  => $this->service(DeviceHeartbeatRepository::class)->seriesForDevice($deviceId, $hours),
            'requests'    => $this->service(ApiRequestLogRepository::class)->forDevice($deviceId),
        ]);
    }

    /**
     * POST /api/v1/devices
     */
    public function store(Request $request): JsonResponse
    {
        $attributes = $this->validate($request, array_merge($this->rules(), [
            'device_code' => 'nullable|string|max:40|unique:devices,device_code',
        ]), $this->labels());

        $result = $this->service(DeviceService::class)->register($attributes, $this->auth->id());

        // The plain key travels back exactly once. It is not stored anywhere
        // that can return it again, so the interface must show it now.
        return ApiResponse::created('The station was registered. Copy the API key now — it is not shown again.', [
            'device_id' => $result['device_id'],
            'api_key'   => $result['api_key'],
        ], '/api/v1/devices/' . $result['device_id']);
    }

    /**
     * PUT /api/v1/devices/{id}
     */
    public function update(Request $request): JsonResponse
    {
        $deviceId   = $request->routeInt('id');
        $attributes = $this->validate($request, $this->rules(), $this->labels());

        $this->service(DeviceService::class)->update($deviceId, $attributes, $this->auth->id());

        return $this->json('The station was updated.', ['device_id' => $deviceId]);
    }

    /**
     * POST /api/v1/devices/{id}/rotate-key
     */
    public function rotateKey(Request $request): JsonResponse
    {
        $deviceId = $request->routeInt('id');

        $key = $this->service(DeviceService::class)->rotateApiKey($deviceId, $this->auth->id());

        return $this->json('A new API key was issued. The station will be refused until it is reflashed with this key.', [
            'device_id' => $deviceId,
            'api_key'   => $key,
        ]);
    }

    /**
     * POST /api/v1/devices/{id}/suspend
     */
    public function suspend(Request $request): JsonResponse
    {
        $deviceId = $request->routeInt('id');

        $payload = $this->validate($request, [
            'minutes' => 'required|integer|between:1,10080',
            'reason'  => 'required|string|min:5|max:255',
        ], [
            'minutes' => 'Suspension length in minutes',
            'reason'  => 'Reason',
        ]);

        $this->service(DeviceService::class)->suspend(
            $deviceId,
            (int) $payload['minutes'],
            (string) $payload['reason']
        );

        return $this->json('The station was suspended and will be refused until the period ends.', [
            'device_id' => $deviceId,
            'minutes'   => (int) $payload['minutes'],
        ]);
    }

    /**
     * POST /api/v1/devices/{id}/reinstate
     */
    public function reinstate(Request $request): JsonResponse
    {
        $deviceId = $request->routeInt('id');

        $this->service(DeviceService::class)->reinstate($deviceId);

        return $this->json('The station was reinstated.', ['device_id' => $deviceId]);
    }

    /**
     * DELETE /api/v1/devices/{id}
     *
     * Decommissioning, not deletion. The station's historical scans remain
     * attributable to it forever.
     */
    public function destroy(Request $request): JsonResponse
    {
        $this->service(DeviceService::class)->decommission($request->routeInt('id'), $this->auth->id());

        return ApiResponse::deleted('The station was decommissioned and its credentials invalidated.');
    }

    /**
     * @return array<string,string>
     */
    private function rules(): array
    {
        return [
            'device_name'        => 'required|string|max:120',
            'description'        => 'nullable|string|max:255',
            'gate_type'          => 'required|in:entry,exit,both',
            'gate_label'         => 'nullable|string|max:60',
            'location'           => 'nullable|string|max:120',
            'mac_address'        => 'nullable|mac',
            'allowed_ip'         => 'nullable|ip',
            'firmware_version'   => 'nullable|string|max:20',
            'installation_date'  => 'nullable|date',
            'heartbeat_interval' => 'nullable|integer|between:5,3600',
            'status'             => 'nullable|in:active,inactive,maintenance,suspended,decommissioned',
            'remarks'            => 'nullable|string|max:2000',
        ];
    }

    /**
     * @return array<string,string>
     */
    private function labels(): array
    {
        return [
            'device_code'        => 'Station code',
            'device_name'        => 'Station name',
            'gate_type'          => 'Gate role',
            'gate_label'         => 'Gate label',
            'mac_address'        => 'MAC address',
            'allowed_ip'         => 'Permitted IP address',
            'firmware_version'   => 'Firmware version',
            'installation_date'  => 'Installation date',
            'heartbeat_interval' => 'Heartbeat interval',
        ];
    }
}
