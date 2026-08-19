<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\ReportService;

/**
 * Report generation and export.
 *
 * One definition per report drives the on-screen table, the CSV, the
 * spreadsheet and the PDF alike, so an exported figure can never disagree with
 * the one shown on the page.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class ReportController extends Controller
{
    /**
     * GET /api/v1/reports
     *
     * Only the reports the signed-in user is allowed to run are listed; a
     * report they cannot run is not a menu item they should see.
     */
    public function index(Request $request): JsonResponse
    {
        $available = $this->service(ReportService::class)->available();

        $catalogue = [];

        foreach ($available as $key => $definition) {
            $catalogue[] = [
                'key'         => $key,
                'title'       => $definition['title'],
                'description' => $definition['description'],
                'headers'     => $definition['headers'],
                'permission'  => $definition['permission'],
            ];
        }

        return $this->json('Available reports retrieved.', $catalogue, 200, [
            'formats' => ['pdf', 'excel', 'csv'],
        ]);
    }

    /**
     * GET /api/v1/reports/{key}
     */
    public function generate(Request $request): JsonResponse
    {
        $key = (string) $request->route('key', '');

        return $this->json(
            'Report generated.',
            $this->service(ReportService::class)->generate($key, $this->filters($request))
        );
    }

    /**
     * GET /api/v1/reports/{key}/export/{format}
     *
     * Returns the file itself rather than a JSON envelope, so a plain link can
     * be used and the browser handles the download.
     */
    public function export(Request $request): Response
    {
        $key    = (string) $request->route('key', '');
        $format = strtolower((string) $request->route('format', 'pdf'));

        if (!in_array($format, ['pdf', 'excel', 'xlsx', 'csv'], true)) {
            return $this->failure('UNSUPPORTED_FORMAT', 'That export format is not supported.', 422, [
                'supported' => ['pdf', 'excel', 'csv'],
            ]);
        }

        return $this->service(ReportService::class)->export($key, $format, $this->filters($request));
    }

    /**
     * GET /api/v1/reports/analytics/overview
     */
    public function analytics(Request $request): JsonResponse
    {
        $to   = $request->string('date_to', now()->format('Y-m-d'));
        $from = $request->string('date_from', now()->modify('-29 days')->format('Y-m-d'));

        return $this->json('Analytics retrieved.', $this->service(ReportService::class)->analytics($from, $to));
    }

    /**
     * The filters a report understands, taken from the query string.
     *
     * Passing the whole query through unfiltered would let a caller inject
     * keys a report definition does not expect; the service normalises what it
     * receives, and this narrows it first.
     *
     * @return array<string,mixed>
     */
    private function filters(Request $request): array
    {
        $accepted = [
            'date_from', 'date_to', 'vehicle_id', 'driver_id', 'owner_id', 'device_id',
            'operator_id', 'vehicle_type_id', 'visitor_type_id', 'status', 'access_type',
            'severity', 'module', 'role_id', 'reason_code', 'search', 'limit',
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
