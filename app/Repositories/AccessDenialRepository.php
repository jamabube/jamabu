<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * Rejected scans.
 *
 * The specification requires that an unregistered tag creates no access
 * record. It does not require that the rejection go unrecorded — the opposite,
 * in fact: rejections must be investigable and must feed the analytics. Keeping
 * them in their own table satisfies both.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class AccessDenialRepository extends BaseRepository
{
    protected string $table = 'access_denials';
    protected string $primaryKey = 'denial_id';
    protected bool $timestamps = false;
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'device_id', 'scanned_uid', 'attempted_type', 'reason_code', 'reason',
        'vehicle_id', 'rfid_tag_id', 'rfid_card_id', 'visitor_log_id', 'plate_number',
        'operator_id', 'ip_address', 'request_id', 'security_event_id', 'occurred_at',
    ];

    protected array $sortable = ['occurred_at', 'reason_code', 'scanned_uid', 'attempted_type'];
    protected array $searchable = ['scanned_uid', 'reason_code', 'reason', 'plate_number'];

    /**
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = (new QueryBuilder($this->connection))
            ->table('access_denials', 'd')
            ->select([
                'd.denial_id', 'd.scanned_uid', 'd.attempted_type', 'd.reason_code', 'd.reason',
                'd.plate_number', 'd.ip_address', 'd.occurred_at', 'd.vehicle_id', 'd.device_id',
            ])
            ->selectRaw('`dv`.`device_name` AS `device_name`')
            ->selectRaw('`u`.`full_name` AS `operator_name`')
            ->leftJoin('devices', 'dv.device_id', 'd.device_id', 'dv')
            ->leftJoin('users', 'u.user_id', 'd.operator_id', 'u');

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike(
                ['d.scanned_uid', 'd.reason_code', 'd.reason', 'd.plate_number'],
                (string) $filters['search']
            );
        }

        foreach (['reason_code' => 'd.reason_code', 'device_id' => 'd.device_id',
                  'attempted_type' => 'd.attempted_type', 'vehicle_id' => 'd.vehicle_id'] as $filter => $column) {
            if (($filters[$filter] ?? '') !== '' && ($filters[$filter] ?? null) !== null) {
                $query->whereEquals($column, $filters[$filter]);
            }
        }

        $query->whereDateRange('d.occurred_at', $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        return $query;
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginate(array $filters, array $options): Paginator
    {
        $query = $this->filtered($filters);
        $query->orderBy('d.' . $this->assertSortable((string) ($options['sort'] ?? 'occurred_at')), (string) ($options['direction'] ?? 'DESC'));

        return Paginator::fromQuery(
            $query,
            max(1, (int) ($options['page'] ?? 1)),
            (int) ($options['per_page'] ?? config('app.pagination.default_per_page', 25))
        );
    }

    /**
     * Recent rejections for the dashboard panel.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 10): array
    {
        return $this->filtered([])->orderBy('d.occurred_at', 'DESC')->limit($limit)->get();
    }

    public function countBetween(string $from, string $to): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `access_denials` WHERE `occurred_at` BETWEEN ? AND ?',
            [$from, $to]
        );
    }

    /**
     * Rejection counts grouped by reason, for the analytics breakdown.
     *
     * @return list<array<string,mixed>>
     */
    public function reasonBreakdown(string $from, string $to): array
    {
        return $this->connection->select(
            'SELECT `reason_code`, MIN(`reason`) AS `reason`, COUNT(*) AS `total`
               FROM `access_denials`
              WHERE `occurred_at` BETWEEN :from AND :to
              GROUP BY `reason_code`
              ORDER BY `total` DESC',
            ['from' => $from, 'to' => $to]
        );
    }

    /**
     * How many times an unregistered UID has been presented recently.
     *
     * Repeated presentation of the same unknown tag is a different situation
     * from a one-off misread and is worth escalating.
     */
    public function countRecentForUid(string $scannedUid, int $withinSeconds): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `access_denials`
              WHERE `scanned_uid` = ? AND `occurred_at` >= ?',
            [
                $scannedUid,
                now()->modify('-' . max(1, $withinSeconds) . ' seconds')->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * The rejection rate across a period, as a percentage of all scans.
     */
    public function rejectionRate(string $from, string $to): float
    {
        $denials = $this->countBetween($from, $to);

        $granted = (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `vehicle_access_logs` WHERE `entry_time` BETWEEN ? AND ?',
            [$from, $to]
        );

        $total = $denials + $granted;

        return $total === 0 ? 0.0 : round(($denials / $total) * 100, 2);
    }
}
