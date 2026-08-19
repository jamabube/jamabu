<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\AccessLogRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\ReferenceRepository;

/**
 * Monitoring pages: the live feed, the history, who is inside and the
 * refused-scan register.
 *
 * @package App\Controllers\Web
 * @version 1.0.0
 */
final class MonitoringController extends Controller
{
    /**
     * GET /monitoring/live
     */
    public function live(Request $request): Response
    {
        return $this->render('pages/monitoring/live', [
            'title'    => 'Live monitoring',
            'feed'     => $this->service(AccessLogRepository::class)->liveFeed(
                (int) config('monitoring.live.feed_size', 25)
            ),
            'devices'  => $this->service(DeviceRepository::class)->allWithStatus(),
            'interval' => (int) config('monitoring.live.poll_interval_seconds', 5),
        ]);
    }

    /**
     * GET /monitoring/history
     */
    public function history(Request $request): Response
    {
        return $this->render('pages/monitoring/history', [
            'title'         => 'Access history',
            'devices'       => $this->service(DeviceRepository::class)->allWithStatus(),
            'vehicleTypes'  => $this->service(ReferenceRepository::class)->vehicleTypes(),
            'results'       => (array) config('monitoring.results', []),
            'canForceClose' => $this->auth->can('monitoring.force_close'),
            'canAnnotate'   => $this->auth->can('monitoring.annotate'),
            'canExport'     => $this->auth->can('monitoring.export'),
        ]);
    }

    /**
     * GET /monitoring/inside
     */
    public function inside(Request $request): Response
    {
        return $this->render('pages/monitoring/inside', [
            'title'        => 'Vehicles inside',
            'inside'       => $this->service(AccessLogRepository::class)->currentlyInside(),
            'overstaying'  => $this->service(AccessLogRepository::class)->overstaying(
                (int) config('monitoring.rules.overstay_alert_hours', 24)
            ),
            'canManual'    => $this->auth->can('monitoring.manual'),
            'devices'      => $this->service(DeviceRepository::class)->allWithStatus(),
        ]);
    }

    /**
     * GET /monitoring/denials
     */
    public function denials(Request $request): Response
    {
        return $this->render('pages/monitoring/denials', [
            'title'   => 'Refused scans',
            'devices' => $this->service(DeviceRepository::class)->allWithStatus(),
            'results' => (array) config('monitoring.results', []),
        ]);
    }

    /**
     * GET /monitoring/{id}
     */
    public function show(Request $request): Response
    {
        $accessLogId = $request->routeInt('id');
        $record      = $this->service(AccessLogRepository::class)->findInView($accessLogId);

        if ($record === null) {
            return $this->render('errors/404', ['title' => 'Record not found'], 404);
        }

        return $this->render('pages/monitoring/show', [
            'title'  => 'Monitoring record ' . (string) $record['transaction_reference'],
            'record' => $record,
        ]);
    }
}
