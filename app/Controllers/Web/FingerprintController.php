<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\DeviceRepository;
use App\Repositories\DriverRepository;
use App\Repositories\OperatorSessionRepository;
use App\Services\FingerprintService;

/**
 * Fingerprint enrolment page.
 *
 * @package App\Controllers\Web
 * @version 1.0.0
 */
final class FingerprintController extends Controller
{
    /**
     * GET /fingerprints
     */
    public function index(Request $request): Response
    {
        return $this->render('pages/fingerprints/index', [
            'title'    => 'Fingerprint enrolments',
            'summary'  => $this->service(FingerprintService::class)->summary(),
            'devices'  => $this->service(DeviceRepository::class)->allWithStatus(),
            'drivers'  => $this->service(DriverRepository::class)->selectList(),
            'onDuty'   => $this->service(OperatorSessionRepository::class)->history(['status' => 'active'], 50),
            'capacity' => (int) config('monitoring.fingerprint.sensor_capacity', 1000),
            'can'      => [
                'enroll' => $this->auth->can('fingerprints.enroll'),
                'delete' => $this->auth->can('fingerprints.delete'),
                'sync'   => $this->auth->can('fingerprints.sync'),
                'verify' => $this->auth->can('fingerprints.verify'),
            ],
        ]);
    }
}
