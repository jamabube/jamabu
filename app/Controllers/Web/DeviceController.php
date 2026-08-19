<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\DeviceHeartbeatRepository;
use App\Repositories\DeviceRepository;
use App\Services\DeviceService;

/**
 * Monitoring station pages.
 *
 * @package App\Controllers\Web
 * @version 1.0.0
 */
final class DeviceController extends Controller
{
    /**
     * GET /devices
     */
    public function index(Request $request): Response
    {
        return $this->render('pages/devices/index', [
            'title'   => 'ESP32 devices',
            'summary' => $this->service(DeviceService::class)->summary(),
            'devices' => $this->service(DeviceService::class)->allWithStatus(),
            'can'     => [
                'create'      => $this->auth->can('devices.create'),
                'update'      => $this->auth->can('devices.update'),
                'delete'      => $this->auth->can('devices.delete'),
                'suspend'     => $this->auth->can('devices.suspend'),
                'rotate'      => $this->auth->can('devices.rotate_key'),
                'diagnostics' => $this->auth->can('devices.diagnostics'),
            ],
        ]);
    }

    /**
     * GET /devices/{id}
     */
    public function show(Request $request): Response
    {
        $deviceId = $request->routeInt('id');
        $device   = $this->service(DeviceRepository::class)->findWithStatus($deviceId);

        if ($device === null) {
            return $this->render('errors/404', ['title' => 'Device not found'], 404);
        }

        return $this->render('pages/devices/show', [
            'title'       => (string) $device['device_name'],
            'device'      => $device,
            'diagnostics' => $this->service(DeviceService::class)->diagnostics($deviceId),
            'heartbeats'  => $this->service(DeviceHeartbeatRepository::class)->seriesForDevice($deviceId, 6),
        ]);
    }
}
