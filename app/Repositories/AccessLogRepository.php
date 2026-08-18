<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * The official monitoring record.
 *
 * One row per visit: created when a vehicle is granted entry, completed when
 * it is granted exit. Rejected scans live in AccessDenialRepository instead, so
 * this table contains only movements that actually happened.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class AccessLogRepository extends BaseRepository
{
    protected string $table = 'vehicle_access_logs';
    protected string $primaryKey = 'access_log_id';
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'transaction_reference', 'vehicle_id', 'visitor_log_id', 'driver_id', 'rfid_tag_id',
        'rfid_card_id', 'scanned_uid', 'plate_number', 'entry_device_id', 'entry_time',
        'entry_operator_id', 'entry_operator_session_id', 'entry_verification', 'entry_request_id',
        'exit_device_id', 'exit_time', 'exit_operator_id', 'exit_operator_session_id',
        'exit_verification', 'exit_request_id', 'access_type', 'status', 'result',
        'is_visitor', 'remarks',
    ];

    protected array $sortable = [
        'entry_time', 'exit_time', 'duration_seconds', 'plate_number', 'status',
        'access_type', 'transaction_reference', 'created_at', 'owner_name', 'driver_name',
        'entry_device_name', 'vehicle_type',
    ];

    protected array $searchable = [
        'plate_number', 'scanned_uid', 'transaction_reference', 'owner_name',
        'driver_name', 'visitor_name', 'tag_code', 'card_code', 'entry_device_name',
        'entry_operator_name',
    ];

    /**
     * Query the monitoring view, which carries the joined vehicle, owner,
     * driver, device and operator detail every screen needs.
     */
    public function monitoring(): QueryBuilder
    {
        return (new QueryBuilder($this->connection))->table('v_access_monitoring');
    }

    /**
     * The open visit for a vehicle, if it is currently inside.
     *
     * @return array<string,mixed>|null
     */
    public function openVisitForVehicle(int $vehicleId): ?array
    {
        return $this->query()
            ->whereEquals('vehicle_id', $vehicleId)
            ->whereEquals('status', 'inside')
            ->first();
    }

    /**
     * The open visit for a visitor pass, if the visitor is inside.
     *
     * @return array<string,mixed>|null
     */
    public function openVisitForVisitorLog(int $visitorLogId): ?array
    {
        return $this->query()
            ->whereEquals('visitor_log_id', $visitorLogId)
            ->whereEquals('status', 'inside')
            ->first();
    }

    /**
     * The most recent movement recorded for a UID at a device.
     *
     * Used to suppress a duplicate transmission: a long-range reader can report
     * the same tag several times as a vehicle rolls past the antenna.
     *
     * @return array<string,mixed>|null
     */
    public function lastMovementForUid(string $scannedUid, int $withinSeconds): ?array
    {
        return $this->query()
            ->whereEquals('scanned_uid', $scannedUid)
            ->where('created_at', '>=', now()->modify('-' . max(1, $withinSeconds) . ' seconds')->format('Y-m-d H:i:s'))
            ->orderBy('created_at', 'DESC')
            ->first();
    }

    /**
     * Close an open visit by recording its exit.
     *
     * @param array<string,mixed> $exitDetails
     */
    public function recordExit(int $accessLogId, array $exitDetails): int
    {
        return $this->connection->execute(
            'UPDATE `vehicle_access_logs`
                SET `exit_device_id` = :exitDevice,
                    `exit_time` = :exitTime,
                    `exit_operator_id` = :exitOperator,
                    `exit_operator_session_id` = :exitOperatorSession,
                    `exit_verification` = :exitVerification,
                    `exit_request_id` = :exitRequestId,
                    `access_type` = :accessType,
                    `status` = :status,
                    `updated_at` = :now
              WHERE `access_log_id` = :id AND `status` = :openStatus',
            [
                'exitDevice'          => $exitDetails['exit_device_id'] ?? null,
                'exitTime'            => $exitDetails['exit_time'],
                'exitOperator'        => $exitDetails['exit_operator_id'] ?? null,
                'exitOperatorSession' => $exitDetails['exit_operator_session_id'] ?? null,
                'exitVerification'    => $exitDetails['exit_verification'] ?? 'rfid',
                'exitRequestId'       => $exitDetails['exit_request_id'] ?? null,
                'accessType'          => 'exit',
                'status'              => 'completed',
                'now'                 => $this->timestamp(),
                'id'                  => $accessLogId,
                'openStatus'          => 'inside',
            ]
        );
    }

    /**
     * Close a visit administratively, when an exit was never scanned.
     */
    public function forceClose(int $accessLogId, string $exitTime, int $closedBy, string $reason): int
    {
        return $this->connection->execute(
            'UPDATE `vehicle_access_logs`
                SET `exit_time` = :exitTime,
                    `status` = :status,
                    `access_type` = :accessType,
                    `force_closed_by` = :closedBy,
                    `force_close_reason` = :reason,
                    `updated_at` = :now
              WHERE `access_log_id` = :id AND `status` = :openStatus',
            [
                'exitTime'   => $exitTime,
                'status'     => 'force_closed',
                'accessType' => 'exit',
                'closedBy'   => $closedBy,
                'reason'     => mb_substr($reason, 0, 255),
                'now'        => $this->timestamp(),
                'id'         => $accessLogId,
                'openStatus' => 'inside',
            ]
        );
    }

    /**
     * Attach an administrative annotation beside the original record.
     */
    public function annotate(int $accessLogId, string $annotation, int $annotatedBy): int
    {
        return $this->connection->execute(
            'UPDATE `vehicle_access_logs`
                SET `annotation` = :annotation, `annotated_by` = :by, `annotated_at` = :now, `updated_at` = :now
              WHERE `access_log_id` = :id',
            [
                'annotation' => $annotation,
                'by'         => $annotatedBy,
                'now'        => $this->timestamp(),
                'id'         => $accessLogId,
            ]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findInView(int $accessLogId): ?array
    {
        return $this->monitoring()->whereEquals('access_log_id', $accessLogId)->first();
    }

    // ------------------------------------------------------------------
    // Listings
    // ------------------------------------------------------------------

    /**
     * Build the filtered monitoring query used by history, exports and reports.
     *
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->monitoring();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike($this->searchable, (string) $filters['search']);
        }

        foreach ([
            'vehicle_id'        => 'vehicle_id',
            'driver_id'         => 'driver_id',
            'rfid_tag_id'       => 'rfid_tag_id',
            'entry_device_id'   => 'entry_device_id',
            'exit_device_id'    => 'exit_device_id',
            'entry_operator_id' => 'entry_operator_id',
            'status'            => 'status',
            'access_type'       => 'access_type',
            'result'            => 'result',
            'vehicle_type'      => 'vehicle_type',
        ] as $filter => $column) {
            if (($filters[$filter] ?? '') !== '' && ($filters[$filter] ?? null) !== null) {
                $query->whereEquals($column, $filters[$filter]);
            }
        }

        if (($filters['is_visitor'] ?? '') !== '') {
            $query->whereEquals('is_visitor', (int) (bool) $filters['is_visitor']);
        }

        if (($filters['plate_number'] ?? '') !== '') {
            $query->whereLike('plate_number', (string) $filters['plate_number']);
        }

        // A device filter that does not care which side of the movement it was.
        if (($filters['device_id'] ?? '') !== '') {
            $deviceId = (int) $filters['device_id'];
            $query->whereRaw(
                '`entry_device_id` = :deviceFilter OR `exit_device_id` = :deviceFilterExit',
                ['deviceFilter' => $deviceId, 'deviceFilterExit' => $deviceId]
            );
        }

        $query->whereDateRange('entry_time', $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        // Stay-duration bounds, expressed in minutes by the interface.
        if (($filters['min_minutes'] ?? '') !== '') {
            $query->where('duration_seconds', '>=', (int) $filters['min_minutes'] * 60);
        }

        if (($filters['max_minutes'] ?? '') !== '') {
            $query->where('duration_seconds', '<=', (int) $filters['max_minutes'] * 60);
        }

        return $query;
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginate(array $filters, array $options): Paginator
    {
        $query = $this->filtered($filters);

        $sort = (string) ($options['sort'] ?? 'entry_time');
        $query->orderBy($this->assertSortable($sort), (string) ($options['direction'] ?? 'DESC'));

        return Paginator::fromQuery(
            $query,
            max(1, (int) ($options['page'] ?? 1)),
            (int) ($options['per_page'] ?? config('app.pagination.default_per_page', 25))
        );
    }

    /**
     * Vehicles currently inside the premises.
     *
     * @return list<array<string,mixed>>
     */
    public function currentlyInside(int $limit = 200): array
    {
        return $this->monitoring()
            ->whereEquals('status', 'inside')
            ->orderBy('entry_time', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * The newest movements, for the live feed.
     *
     * @param int $sinceId Return only records newer than this id.
     *
     * @return list<array<string,mixed>>
     */
    public function liveFeed(int $limit = 25, int $sinceId = 0): array
    {
        $query = $this->monitoring()->orderBy('access_log_id', 'DESC')->limit($limit);

        if ($sinceId > 0) {
            $query->where('access_log_id', '>', $sinceId);
        }

        return $query->get();
    }

    /**
     * Movement history for one vehicle, for its timeline.
     *
     * @return list<array<string,mixed>>
     */
    public function timelineForVehicle(int $vehicleId, int $limit = 50): array
    {
        return $this->monitoring()
            ->whereEquals('vehicle_id', $vehicleId)
            ->orderBy('entry_time', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Open visits that have run past the overstay threshold.
     *
     * @return list<array<string,mixed>>
     */
    public function overstaying(int $hours): array
    {
        if ($hours <= 0) {
            return [];
        }

        return $this->monitoring()
            ->whereEquals('status', 'inside')
            ->where('entry_time', '<', now()->modify('-' . $hours . ' hours')->format('Y-m-d H:i:s'))
            ->orderBy('entry_time', 'ASC')
            ->get();
    }

    // ------------------------------------------------------------------
    // Aggregates
    // ------------------------------------------------------------------

    public function countInside(): int
    {
        return $this->query()->whereEquals('status', 'inside')->count();
    }

    /**
     * Entries recorded between two moments.
     */
    public function countEntriesBetween(string $from, string $to): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `vehicle_access_logs` WHERE `entry_time` BETWEEN ? AND ?',
            [$from, $to]
        );
    }

    /**
     * Exits recorded between two moments.
     */
    public function countExitsBetween(string $from, string $to): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `vehicle_access_logs` WHERE `exit_time` BETWEEN ? AND ?',
            [$from, $to]
        );
    }

    /**
     * Hourly movement counts for a day, backing the peak-hours chart.
     *
     * @return list<array{hour:int,entries:int,exits:int}>
     */
    public function hourlyBreakdown(string $date): array
    {
        $entries = $this->connection->select(
            'SELECT HOUR(`entry_time`) AS `hour`, COUNT(*) AS `total`
               FROM `vehicle_access_logs`
              WHERE DATE(`entry_time`) = :date
              GROUP BY HOUR(`entry_time`)',
            ['date' => $date]
        );

        $exits = $this->connection->select(
            'SELECT HOUR(`exit_time`) AS `hour`, COUNT(*) AS `total`
               FROM `vehicle_access_logs`
              WHERE DATE(`exit_time`) = :date
              GROUP BY HOUR(`exit_time`)',
            ['date' => $date]
        );

        // Every hour is present, so the chart has a continuous axis rather than
        // gaps where nothing happened.
        $breakdown = [];
        for ($hour = 0; $hour < 24; $hour++) {
            $breakdown[$hour] = ['hour' => $hour, 'entries' => 0, 'exits' => 0];
        }

        foreach ($entries as $row) {
            $breakdown[(int) $row['hour']]['entries'] = (int) $row['total'];
        }

        foreach ($exits as $row) {
            $breakdown[(int) $row['hour']]['exits'] = (int) $row['total'];
        }

        return array_values($breakdown);
    }

    /**
     * Daily totals across a range, from the pre-aggregated view.
     *
     * @return list<array<string,mixed>>
     */
    public function dailySummary(string $from, string $to): array
    {
        return $this->connection->select(
            'SELECT * FROM `v_daily_access_summary`
              WHERE `activity_date` BETWEEN :from AND :to
              ORDER BY `activity_date`',
            ['from' => $from, 'to' => $to]
        );
    }

    /**
     * Busiest vehicles in a period.
     *
     * @return list<array<string,mixed>>
     */
    public function mostActiveVehicles(string $from, string $to, int $limit = 10): array
    {
        return $this->connection->select(
            'SELECT l.`vehicle_id`, l.`plate_number`, COUNT(*) AS `visits`,
                    ROUND(AVG(l.`duration_seconds`)) AS `average_stay_seconds`,
                    MAX(l.`entry_time`) AS `last_visit`
               FROM `vehicle_access_logs` l
              WHERE l.`entry_time` BETWEEN :from AND :to AND l.`vehicle_id` IS NOT NULL
              GROUP BY l.`vehicle_id`, l.`plate_number`
              ORDER BY `visits` DESC
              LIMIT ' . max(1, $limit),
            ['from' => $from, 'to' => $to]
        );
    }

    /**
     * Movement counts per device in a period.
     *
     * @return list<array<string,mixed>>
     */
    public function deviceActivity(string $from, string $to): array
    {
        return $this->connection->select(
            'SELECT d.`device_id`, d.`device_name`, d.`gate_type`,
                    SUM(CASE WHEN l.`entry_device_id` = d.`device_id` THEN 1 ELSE 0 END) AS `entries`,
                    SUM(CASE WHEN l.`exit_device_id` = d.`device_id` THEN 1 ELSE 0 END) AS `exits`
               FROM `devices` d
               LEFT JOIN `vehicle_access_logs` l
                      ON (l.`entry_device_id` = d.`device_id` OR l.`exit_device_id` = d.`device_id`)
                     AND l.`entry_time` BETWEEN :from AND :to
              WHERE d.`deleted_at` IS NULL
              GROUP BY d.`device_id`, d.`device_name`, d.`gate_type`
              ORDER BY d.`device_name`',
            ['from' => $from, 'to' => $to]
        );
    }

    /**
     * Headline analytics for a period.
     *
     * @return array<string,mixed>
     */
    public function analytics(string $from, string $to): array
    {
        $row = $this->connection->selectOne(
            "SELECT COUNT(*) AS `total_visits`,
                    COUNT(DISTINCT `vehicle_id`) AS `unique_vehicles`,
                    SUM(`is_visitor` = 1) AS `visitor_visits`,
                    SUM(`status` = 'inside') AS `still_inside`,
                    ROUND(AVG(`duration_seconds`)) AS `average_stay_seconds`,
                    MAX(`duration_seconds`) AS `longest_stay_seconds`,
                    MIN(`duration_seconds`) AS `shortest_stay_seconds`
               FROM `vehicle_access_logs`
              WHERE `entry_time` BETWEEN :from AND :to",
            ['from' => $from, 'to' => $to]
        ) ?? [];

        $peakEntry = $this->connection->selectOne(
            'SELECT HOUR(`entry_time`) AS `hour`, COUNT(*) AS `total`
               FROM `vehicle_access_logs`
              WHERE `entry_time` BETWEEN :from AND :to
              GROUP BY HOUR(`entry_time`)
              ORDER BY `total` DESC
              LIMIT 1',
            ['from' => $from, 'to' => $to]
        );

        $peakExit = $this->connection->selectOne(
            'SELECT HOUR(`exit_time`) AS `hour`, COUNT(*) AS `total`
               FROM `vehicle_access_logs`
              WHERE `exit_time` BETWEEN :from AND :to
              GROUP BY HOUR(`exit_time`)
              ORDER BY `total` DESC
              LIMIT 1',
            ['from' => $from, 'to' => $to]
        );

        return [
            'total_visits'          => (int) ($row['total_visits'] ?? 0),
            'unique_vehicles'       => (int) ($row['unique_vehicles'] ?? 0),
            'visitor_visits'        => (int) ($row['visitor_visits'] ?? 0),
            'still_inside'          => (int) ($row['still_inside'] ?? 0),
            'average_stay_seconds'  => (int) ($row['average_stay_seconds'] ?? 0),
            'longest_stay_seconds'  => (int) ($row['longest_stay_seconds'] ?? 0),
            'shortest_stay_seconds' => (int) ($row['shortest_stay_seconds'] ?? 0),
            'peak_entry_hour'       => $peakEntry === null ? null : (int) $peakEntry['hour'],
            'peak_exit_hour'        => $peakExit === null ? null : (int) $peakExit['hour'],
        ];
    }

    /**
     * Generate the next transaction reference.
     *
     * The random suffix keeps two stations that scan in the same second from
     * colliding on the unique index.
     */
    public function nextReference(): string
    {
        $today = now()->format('Ymd');

        $sequence = (int) $this->connection->scalar(
            'SELECT COUNT(*) + 1 FROM `vehicle_access_logs` WHERE DATE(`created_at`) = ?',
            [now()->format('Y-m-d')]
        );

        return sprintf('ACC-%s-%04d-%s', $today, $sequence, \App\Core\Support\Str::randomCode(4));
    }
}
