<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\DeviceRepository;
use App\Repositories\ReferenceRepository;
use App\Services\ReportService;

/**
 * Report and analytics pages.
 *
 * @package App\Controllers\Web
 * @version 1.0.0
 */
final class ReportController extends Controller
{
    /**
     * GET /reports
     */
    public function index(Request $request): Response
    {
        return $this->render('pages/reports/index', [
            'title'        => 'Reports',
            'reports'      => $this->service(ReportService::class)->available(),
            'devices'      => $this->service(DeviceRepository::class)->allWithStatus(),
            'vehicleTypes' => $this->service(ReferenceRepository::class)->vehicleTypes(),
            'canExport'    => $this->auth->can('reports.export'),
        ]);
    }

    /**
     * GET /reports/{key}
     */
    public function show(Request $request): Response
    {
        $key = (string) $request->route('key', '');

        return $this->render('pages/reports/show', [
            'title'     => 'Report',
            'report'    => $this->service(ReportService::class)->generate($key, $this->filters($request)),
            'canExport' => $this->auth->can('reports.export'),
        ]);
    }

    /**
     * GET /reports/analytics
     */
    public function analytics(Request $request): Response
    {
        $to   = $request->string('date_to', now()->format('Y-m-d'));
        $from = $request->string('date_from', now()->modify('-29 days')->format('Y-m-d'));

        return $this->render('pages/reports/analytics', [
            'title'     => 'Analytics',
            'analytics' => $this->service(ReportService::class)->analytics($from, $to),
            'date_from' => $from,
            'date_to'   => $to,
        ]);
    }

    /**
     * The filters a report accepts from the query string.
     *
     * @return array<string,mixed>
     */
    private function filters(Request $request): array
    {
        $accepted = [
            'date_from', 'date_to', 'vehicle_id', 'driver_id', 'owner_id', 'device_id',
            'operator_id', 'vehicle_type_id', 'visitor_type_id', 'status', 'access_type',
            'severity', 'module', 'role_id', 'reason_code', 'search',
        ];

        $filters = [];

        foreach ($accepted as $key) {
            $value = $request->string($key);

            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        return $filters;
    }
}
