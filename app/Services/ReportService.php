<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Export\CsvWriter;
use App\Core\Export\PdfWriter;
use App\Core\Export\SpreadsheetWriter;
use App\Core\Http\Response;
use App\Core\Security\AuthGuard;
use App\Core\Support\Str;
use App\Exceptions\AuthorizationException;
use App\Exceptions\NotFoundException;
use App\Repositories\AccessDenialRepository;
use App\Repositories\AccessLogRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\DriverRepository;
use App\Repositories\ErrorLogRepository;
use App\Repositories\RfidTagRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\VehicleRepository;
use App\Repositories\VisitorLogRepository;

/**
 * Report generation and export.
 *
 * Every report is declared once, as a definition naming its permission, its
 * columns and how to fetch its rows. The generation and export paths are then
 * generic, so adding a report is a matter of adding a definition rather than
 * writing another export routine.
 *
 * @package App\Services
 * @version 1.0.0
 */
class ReportService
{
    /** Rows beyond this are refused rather than exhausting memory. */
    private const MAXIMUM_ROWS = 50000;

    public function __construct(
        private readonly AccessLogRepository $accessLogs,
        private readonly AccessDenialRepository $denials,
        private readonly VehicleRepository $vehicles,
        private readonly DriverRepository $drivers,
        private readonly RfidTagRepository $tags,
        private readonly VisitorLogRepository $visitorLogs,
        private readonly DeviceRepository $devices,
        private readonly AuditLogRepository $auditLogs,
        private readonly SecurityEventRepository $securityEvents,
        private readonly ErrorLogRepository $errorLogs,
        private readonly AuditService $audit,
        private readonly AuthGuard $auth
    ) {
    }

    /**
     * Every report the signed-in user may run.
     *
     * @return array<string,array<string,mixed>>
     */
    public function available(): array
    {
        return array_filter(
            $this->definitions(),
            fn (array $definition): bool => $this->auth->can((string) $definition['permission'])
        );
    }

    /**
     * Run a report and return its rows plus metadata.
     *
     * @param array<string,mixed> $filters
     *
     * @return array{key:string,title:string,description:string,headers:list<string>,columns:list<string>,rows:list<array<string,mixed>>,summary:array<string,mixed>,filters:array<string,mixed>,truncated:bool}
     *
     * @throws NotFoundException
     * @throws AuthorizationException
     */
    public function generate(string $key, array $filters = []): array
    {
        $definition = $this->definitions()[$key] ?? null;

        if ($definition === null) {
            throw NotFoundException::record('Report', $key);
        }

        if ($this->auth->cannot((string) $definition['permission'])) {
            throw AuthorizationException::forPermission((string) $definition['permission']);
        }

        $filters = $this->normaliseFilters($filters);

        /** @var callable(array<string,mixed>):list<array<string,mixed>> $fetch */
        $fetch = $definition['rows'];
        $rows  = $fetch($filters);

        // A runaway range would otherwise try to render a hundred thousand rows
        // into a PDF and exhaust memory. The result is truncated and the fact
        // is reported rather than silently producing a partial report.
        $truncated = count($rows) > self::MAXIMUM_ROWS;

        if ($truncated) {
            $rows = array_slice($rows, 0, self::MAXIMUM_ROWS);
        }

        /** @var callable(array<string,mixed>,list<array<string,mixed>>):array<string,mixed> $summarise */
        $summarise = $definition['summary'];

        return [
            'key'         => $key,
            'title'       => (string) $definition['title'],
            'description' => (string) $definition['description'],
            'headers'     => (array) $definition['headers'],
            'columns'     => (array) $definition['columns'],
            'weights'     => $definition['weights'] ?? null,
            'landscape'   => (bool) ($definition['landscape'] ?? false),
            'rows'        => $rows,
            'summary'     => $summarise($filters, $rows),
            'filters'     => $filters,
            'truncated'   => $truncated,
        ];
    }

    /**
     * Run a report and return it as a downloadable file.
     *
     * @param array<string,mixed> $filters
     */
    public function export(string $key, string $format, array $filters = []): Response
    {
        $report   = $this->generate($key, $filters);
        $filename = sprintf(
            '%s-%s',
            Str::slug($report['title']),
            now()->format('Ymd-His')
        );

        $this->audit->record('reports', 'exported', sprintf(
            'The "%s" report was exported as %s (%d row(s)).',
            $report['title'],
            strtoupper($format),
            count($report['rows'])
        ), ['record_type' => 'reports', 'record_id' => $key]);

        return match (strtolower($format)) {
            'csv'   => Response::download(
                CsvWriter::build($report['headers'], $report['rows'], $report['columns']),
                $filename . '.csv',
                'text/csv; charset=UTF-8'
            ),
            'excel', 'xlsx' => Response::download(
                SpreadsheetWriter::build(
                    $report['headers'],
                    $report['rows'],
                    $report['columns'],
                    Str::limit($report['title'], 28, ''),
                    ['title' => $report['title'], 'generated_by' => $this->auth->displayName()]
                ),
                $filename . '.xlsx',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            ),
            default => Response::download(
                $this->renderPdf($report),
                $filename . '.pdf',
                'application/pdf'
            ),
        };
    }

    /**
     * Render a generated report as a PDF.
     *
     * @param array<string,mixed> $report
     */
    private function renderPdf(array $report): string
    {
        /** @var array<string,mixed> $filters */
        $filters = $report['filters'];

        $pdf = new PdfWriter(
            title: (string) $report['title'],
            subtitle: $this->describePeriod($filters),
            organisation: (string) config('app.organization', ''),
            generatedBy: $this->auth->displayName(),
            filters: $this->describeFilters($filters),
            landscape: (bool) $report['landscape']
        );

        /** @var array<string,mixed> $summary */
        $summary = $report['summary'];

        if ($summary !== []) {
            $pdf->summary('Summary', array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                $summary
            ));
        }

        if ($report['truncated'] === true) {
            $pdf->paragraph(sprintf(
                'This report was truncated at %s rows. Narrow the date range or the filters to see the remainder.',
                number_format(self::MAXIMUM_ROWS)
            ));
        }

        $pdf->heading('Records');

        if ($report['rows'] === []) {
            $pdf->paragraph('No records match the selected filters.');
        } else {
            $pdf->table(
                (array) $report['headers'],
                (array) $report['rows'],
                (array) $report['columns'],
                $report['weights']
            );
        }

        return $pdf->output();
    }

    // ------------------------------------------------------------------
    // Report catalogue
    // ------------------------------------------------------------------

    /**
     * Every report the system can produce.
     *
     * @return array<string,array<string,mixed>>
     */
    private function definitions(): array
    {
        return [
            'vehicle_movements' => [
                'title'       => 'Vehicle Movement Report',
                'description' => 'Every recorded entry and exit within the selected period.',
                'permission'  => 'reports.view',
                'group'       => 'Monitoring',
                'landscape'   => true,
                'headers'     => ['Reference', 'Plate', 'Owner', 'Driver', 'Type', 'Entry', 'Exit', 'Duration', 'Entry station', 'Operator', 'Status'],
                'columns'     => ['transaction_reference', 'plate_number', 'owner_name', 'driver_name', 'vehicle_type', 'entry_time', 'exit_time', 'duration', 'entry_device_name', 'entry_operator_name', 'status'],
                'weights'     => [2.2, 1.2, 1.8, 1.8, 1.1, 1.5, 1.5, 1.0, 1.6, 1.6, 1.0],
                'rows'        => fn (array $filters): array => array_map(
                    $this->decorateMovement(...),
                    $this->accessLogs->filtered($filters)->orderBy('entry_time', 'DESC')->limit(self::MAXIMUM_ROWS + 1)->get()
                ),
                'summary'     => fn (array $filters, array $rows): array => $this->movementSummary($filters, $rows),
            ],

            'vehicles_inside' => [
                'title'       => 'Vehicles Currently Inside',
                'description' => 'Every vehicle with an open entry record at the moment the report was produced.',
                'permission'  => 'monitoring.view',
                'group'       => 'Monitoring',
                'headers'     => ['Plate', 'Owner', 'Driver', 'Type', 'Entered at', 'Time inside', 'Station'],
                'columns'     => ['plate_number', 'owner_name', 'driver_name', 'vehicle_type', 'entry_time', 'duration', 'entry_device_name'],
                'weights'     => [1.2, 2.0, 2.0, 1.2, 1.6, 1.2, 1.8],
                'rows'        => function (array $filters): array {
                    $rows = $this->accessLogs->currentlyInside(self::MAXIMUM_ROWS);

                    return array_map(function (array $row): array {
                        // A vehicle still inside has no exit, so the useful
                        // figure is how long it has been here so far.
                        $entered = strtotime((string) $row['entry_time']);
                        $row['duration'] = $entered === false ? '—' : Str::duration(time() - $entered);

                        return $row;
                    }, $rows);
                },
                'summary'     => fn (array $filters, array $rows): array => [
                    'Vehicles inside' => count($rows),
                    'Longest stay'    => $rows === [] ? '—' : (string) ($rows[count($rows) - 1]['duration'] ?? '—'),
                    'As at'           => now()->format('Y-m-d H:i:s'),
                ],
            ],

            'rejected_scans' => [
                'title'       => 'Rejected Scan Report',
                'description' => 'Scans that were refused, with the reason for each.',
                'permission'  => 'reports.view',
                'group'       => 'Monitoring',
                'headers'     => ['Occurred at', 'UID', 'Attempted', 'Reason', 'Plate', 'Station', 'Operator'],
                'columns'     => ['occurred_at', 'scanned_uid', 'attempted_type', 'reason', 'plate_number', 'device_name', 'operator_name'],
                'weights'     => [1.6, 1.3, 1.0, 2.6, 1.2, 1.8, 1.8],
                'rows'        => fn (array $filters): array => $this->denials
                    ->filtered($filters)->orderBy('d.occurred_at', 'DESC')->limit(self::MAXIMUM_ROWS + 1)->get(),
                'summary'     => fn (array $filters, array $rows): array => [
                    'Total rejections' => count($rows),
                    'Rejection rate'   => sprintf('%.2f%%', $this->denials->rejectionRate($filters['from'], $filters['to'])),
                    'Most common'      => $this->mostCommonReason($filters),
                    'Period'           => $this->describePeriod($filters),
                ],
            ],

            'visitor_activity' => [
                'title'       => 'Visitor Activity Report',
                'description' => 'Temporary passes issued within the period and how each was used.',
                'permission'  => 'reports.view',
                'group'       => 'Monitoring',
                'landscape'   => true,
                'headers'     => ['Pass', 'Visitor', 'Company', 'Type', 'Purpose', 'Issued', 'Entry', 'Exit', 'Card', 'Authorised by', 'Status'],
                'columns'     => ['pass_reference', 'visitor_name', 'company', 'visitor_type', 'purpose', 'issued_at', 'entry_time', 'exit_time', 'card_code', 'authorised_by_name', 'status'],
                'weights'     => [1.8, 1.8, 1.6, 1.2, 2.4, 1.4, 1.4, 1.4, 0.9, 1.6, 1.0],
                'rows'        => fn (array $filters): array => $this->visitorLogs
                    ->filtered($filters)->orderBy('issued_at', 'DESC')->limit(self::MAXIMUM_ROWS + 1)->get(),
                'summary'     => fn (array $filters, array $rows): array => [
                    'Passes issued'   => count($rows),
                    'Currently inside'=> $this->visitorLogs->countInside(),
                    'Period'          => $this->describePeriod($filters),
                ],
            ],

            'vehicle_registry' => [
                'title'       => 'Vehicle Registry',
                'description' => 'Every registered vehicle with its owner, driver and tag.',
                'permission'  => 'vehicles.view',
                'group'       => 'Registry',
                'landscape'   => true,
                'headers'     => ['Code', 'Plate', 'Type', 'Brand', 'Model', 'Colour', 'Owner', 'Driver', 'Tag', 'Tag expires', 'Status', 'Presence'],
                'columns'     => ['vehicle_code', 'plate_number', 'vehicle_type', 'brand', 'model', 'colour', 'owner_name', 'driver_name', 'tag_code', 'tag_expiration', 'status', 'presence'],
                'weights'     => [1.1, 1.1, 1.2, 1.1, 1.1, 0.9, 1.8, 1.8, 1.0, 1.2, 0.9, 0.9],
                'rows'        => fn (array $filters): array => $this->vehicles
                    ->filtered($filters)->orderBy('plate_number')->limit(self::MAXIMUM_ROWS + 1)->get(),
                'summary'     => fn (array $filters, array $rows): array => array_merge(
                    ['Total vehicles' => count($rows)],
                    array_combine(
                        array_map(static fn (string $k): string => ucfirst($k), array_keys($this->vehicles->statusCounts())),
                        array_values($this->vehicles->statusCounts())
                    )
                ),
            ],

            'driver_registry' => [
                'title'       => 'Driver Registry',
                'description' => 'Authorised drivers, their vehicles and licence status.',
                'permission'  => 'drivers.view',
                'group'       => 'Registry',
                'headers'     => ['Code', 'Name', 'Contact', 'Government ID', 'Licence expiry', 'Vehicles', 'Status'],
                'columns'     => ['driver_code', 'full_name', 'contact_number', 'government_id', 'licence_expiry', 'plate_numbers', 'status'],
                'weights'     => [1.1, 2.2, 1.4, 1.6, 1.3, 2.2, 0.9],
                'rows'        => fn (array $filters): array => $this->drivers
                    ->filtered($filters)->orderBy('d.full_name')->limit(self::MAXIMUM_ROWS + 1)->get(),
                'summary'     => fn (array $filters, array $rows): array => [
                    'Total drivers' => count($rows),
                    'Active'        => $this->drivers->statusCounts()['active'],
                ],
            ],

            'rfid_inventory' => [
                'title'       => 'RFID Inventory Report',
                'description' => 'Windshield tags, their assignment and their lifecycle state.',
                'permission'  => 'rfid.view',
                'group'       => 'Registry',
                'headers'     => ['Code', 'UID', 'Type', 'Assigned vehicle', 'Owner', 'Status', 'Activated', 'Expires', 'Last scan', 'Scans'],
                'columns'     => ['tag_code', 'rfid_uid', 'tag_type', 'plate_number', 'owner_name', 'status', 'activation_date', 'expiration_date', 'last_scanned_at', 'scan_count'],
                'weights'     => [1.0, 1.4, 1.4, 1.3, 1.9, 1.0, 1.2, 1.2, 1.5, 0.8],
                'rows'        => fn (array $filters): array => $this->tags
                    ->filtered($filters)->orderBy('t.tag_code')->limit(self::MAXIMUM_ROWS + 1)->get(),
                'summary'     => fn (array $filters, array $rows): array => array_merge(
                    ['Total tags' => count($rows)],
                    array_combine(
                        array_map(static fn (string $k): string => ucfirst($k), array_keys($this->tags->statusCounts())),
                        array_values($this->tags->statusCounts())
                    )
                ),
            ],

            'device_status' => [
                'title'       => 'Monitoring Station Report',
                'description' => 'Every station, its connectivity and its health.',
                'permission'  => 'devices.view',
                'group'       => 'Infrastructure',
                'headers'     => ['Code', 'Name', 'Location', 'Gate', 'Firmware', 'Connectivity', 'Last heartbeat', 'Signal', 'Restarts', 'Health'],
                'columns'     => ['device_code', 'device_name', 'location', 'gate_type', 'firmware_version', 'connectivity', 'last_heartbeat_at', 'signal_strength', 'restart_count', 'health_score'],
                'weights'     => [1.5, 1.9, 1.9, 0.9, 1.1, 1.2, 1.6, 0.9, 0.9, 0.9],
                'rows'        => fn (array $filters): array => $this->devices->allWithStatus(),
                'summary'     => fn (array $filters, array $rows): array => array_map(
                    static fn (int $value): string => (string) $value,
                    $this->devices->connectivityCounts()
                ),
            ],

            'audit_trail' => [
                'title'       => 'Audit Trail Report',
                'description' => 'Recorded user actions within the period.',
                'permission'  => 'audit.view',
                'group'       => 'Governance',
                'landscape'   => true,
                'headers'     => ['Occurred at', 'User', 'Role', 'Module', 'Action', 'Description', 'Record', 'IP address', 'Result'],
                'columns'     => ['created_at', 'username', 'role_name', 'module', 'action', 'description', 'record_type', 'ip_address', 'status'],
                'weights'     => [1.5, 1.3, 1.3, 1.2, 1.2, 3.4, 1.4, 1.3, 0.9],
                'rows'        => fn (array $filters): array => $this->auditLogs
                    ->filtered($filters)->orderBy('created_at', 'DESC')->limit(self::MAXIMUM_ROWS + 1)->get(),
                'summary'     => fn (array $filters, array $rows): array => [
                    'Records'  => count($rows),
                    'Period'   => $this->describePeriod($filters),
                ],
            ],

            'security_events' => [
                'title'       => 'Security Event Report',
                'description' => 'Detected suspicious activity and how each was handled.',
                'permission'  => 'security.view',
                'group'       => 'Governance',
                'landscape'   => true,
                'headers'     => ['Occurred at', 'Type', 'Severity', 'Description', 'User', 'Device', 'IP address', 'Action taken', 'Status'],
                'columns'     => ['occurred_at', 'event_type', 'severity', 'description', 'username', 'device_code', 'ip_address', 'action_taken', 'status'],
                'weights'     => [1.5, 1.6, 0.9, 3.6, 1.2, 1.5, 1.3, 1.4, 1.0],
                'rows'        => fn (array $filters): array => $this->securityEvents
                    ->filtered($filters)->orderBy('occurred_at', 'DESC')->limit(self::MAXIMUM_ROWS + 1)->get(),
                'summary'     => fn (array $filters, array $rows): array => array_merge(
                    ['Events' => count($rows)],
                    array_combine(
                        array_map(static fn (string $k): string => ucfirst($k), array_keys($this->securityEvents->severityCounts($filters['from']))),
                        array_values($this->securityEvents->severityCounts($filters['from']))
                    )
                ),
            ],

            'error_log' => [
                'title'       => 'Error Log Report',
                'description' => 'Application errors recorded within the period.',
                'permission'  => 'errors.view',
                'group'       => 'Governance',
                'landscape'   => true,
                'headers'     => ['Reference', 'Last seen', 'Severity', 'Module', 'Exception', 'Message', 'Occurrences', 'Resolved'],
                'columns'     => ['reference', 'last_seen_at', 'severity', 'module', 'exception_class', 'message', 'occurrence_count', 'resolved'],
                'weights'     => [1.3, 1.5, 1.0, 1.3, 2.2, 3.4, 1.1, 0.9],
                'rows'        => fn (array $filters): array => $this->errorLogs
                    ->filtered($filters)->orderBy('last_seen_at', 'DESC')->limit(self::MAXIMUM_ROWS + 1)->get(),
                'summary'     => fn (array $filters, array $rows): array => [
                    'Errors'     => count($rows),
                    'Unresolved' => $this->errorLogs->countUnresolved(),
                    'Period'     => $this->describePeriod($filters),
                ],
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Add derived presentation fields to a movement row.
     *
     * @param array<string,mixed> $row
     *
     * @return array<string,mixed>
     */
    private function decorateMovement(array $row): array
    {
        $row['duration'] = $row['duration_seconds'] === null
            ? 'Still inside'
            : Str::duration((int) $row['duration_seconds']);

        $row['exit_time'] ??= '—';

        return $row;
    }

    /**
     * @param array<string,mixed> $filters
     * @param list<array<string,mixed>> $rows
     *
     * @return array<string,mixed>
     */
    private function movementSummary(array $filters, array $rows): array
    {
        $analytics = $this->accessLogs->analytics($filters['from'], $filters['to']);

        return [
            'Records in report' => count($rows),
            'Total visits'      => $analytics['total_visits'],
            'Unique vehicles'   => $analytics['unique_vehicles'],
            'Visitor visits'    => $analytics['visitor_visits'],
            'Still inside'      => $analytics['still_inside'],
            'Average stay'      => Str::duration($analytics['average_stay_seconds']),
            'Longest stay'      => Str::duration($analytics['longest_stay_seconds']),
            'Peak entry hour'   => $analytics['peak_entry_hour'] === null
                ? '—'
                : sprintf('%02d:00', $analytics['peak_entry_hour']),
        ];
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function mostCommonReason(array $filters): string
    {
        $breakdown = $this->denials->reasonBreakdown($filters['from'], $filters['to']);

        return $breakdown === []
            ? '—'
            : sprintf('%s (%d)', (string) $breakdown[0]['reason'], (int) $breakdown[0]['total']);
    }

    /**
     * Fill in a default period and normalise the bounds.
     *
     * @param array<string,mixed> $filters
     *
     * @return array<string,mixed>
     */
    private function normaliseFilters(array $filters): array
    {
        // An unbounded report over years of history is almost never what was
        // intended, so the default window is the current month.
        $filters['date_from'] = (string) ($filters['date_from'] ?? now()->format('Y-m-01'));
        $filters['date_to']   = (string) ($filters['date_to'] ?? now()->format('Y-m-d'));

        $filters['from'] = $filters['date_from'] . ' 00:00:00';
        $filters['to']   = $filters['date_to'] . ' 23:59:59';

        return $filters;
    }

    /**
     * @param array<string,mixed> $filters
     */
    private function describePeriod(array $filters): string
    {
        $from = (string) ($filters['date_from'] ?? '');
        $to   = (string) ($filters['date_to'] ?? '');

        if ($from === $to) {
            return date('j F Y', (int) strtotime($from));
        }

        return sprintf(
            '%s to %s',
            date('j M Y', (int) strtotime($from)),
            date('j M Y', (int) strtotime($to))
        );
    }

    /**
     * Describe the applied filters for the report header.
     *
     * A table of numbers with no statement of what was included is not
     * evidence of anything, so this is printed on every page.
     *
     * @param array<string,mixed> $filters
     *
     * @return list<string>
     */
    private function describeFilters(array $filters): array
    {
        $described = ['Period: ' . $this->describePeriod($filters)];

        $labels = [
            'status'          => 'Status',
            'vehicle_type'    => 'Vehicle type',
            'device_id'       => 'Station',
            'access_type'     => 'Movement',
            'reason_code'     => 'Reason',
            'severity'        => 'Severity',
            'module'          => 'Module',
            'search'          => 'Search',
            'plate_number'    => 'Plate',
        ];

        foreach ($labels as $key => $label) {
            if (($filters[$key] ?? '') !== '' && ($filters[$key] ?? null) !== null) {
                $described[] = sprintf('%s: %s', $label, (string) $filters[$key]);
            }
        }

        if (count($described) === 1) {
            $described[] = 'No additional filters applied.';
        }

        return $described;
    }

    /**
     * Analytics for the analytics page.
     *
     * @return array<string,mixed>
     */
    public function analytics(string $dateFrom, string $dateTo): array
    {
        $from = $dateFrom . ' 00:00:00';
        $to   = $dateTo . ' 23:59:59';

        return [
            'period'          => ['from' => $dateFrom, 'to' => $dateTo],
            'movements'       => $this->accessLogs->analytics($from, $to),
            'daily'           => $this->accessLogs->dailySummary($dateFrom, $dateTo),
            'hourly'          => $this->accessLogs->hourlyBreakdown($dateTo),
            'top_vehicles'    => $this->accessLogs->mostActiveVehicles($from, $to),
            'top_drivers'     => $this->drivers->mostActive($from, $to),
            'top_tags'        => $this->tags->mostUsed($from, $to),
            'device_activity' => $this->accessLogs->deviceActivity($from, $to),
            'denials'         => $this->denials->reasonBreakdown($from, $to),
            'rejection_rate'  => $this->denials->rejectionRate($from, $to),
            'security_trend'  => $this->securityEvents->dailyTrend($from, $to),
        ];
    }
}
