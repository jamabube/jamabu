<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * Authorised driver storage.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class DriverRepository extends BaseRepository
{
    protected string $table = 'drivers';
    protected string $primaryKey = 'driver_id';

    protected array $fillable = [
        'driver_code', 'first_name', 'middle_name', 'last_name', 'suffix', 'address',
        'birth_date', 'gender', 'civil_status', 'contact_number', 'email', 'government_id',
        'licence_expiry', 'emergency_contact_name', 'emergency_contact_number', 'photo',
        'owner_id', 'user_id', 'fingerprint_template_id', 'status', 'remarks',
        'created_by', 'updated_by',
    ];

    protected array $sortable = ['full_name', 'driver_code', 'status', 'licence_expiry', 'created_at'];
    protected array $searchable = ['full_name', 'driver_code', 'contact_number', 'email', 'government_id'];

    /**
     * Drivers with the vehicles assigned to them.
     */
    public function withVehicles(): QueryBuilder
    {
        return (new QueryBuilder($this->connection))
            ->table('drivers', 'd')
            ->select([
                'd.driver_id', 'd.driver_code', 'd.full_name', 'd.first_name', 'd.last_name',
                'd.contact_number', 'd.email', 'd.government_id', 'd.licence_expiry',
                'd.photo', 'd.status', 'd.created_at', 'd.owner_id', 'd.fingerprint_template_id',
            ])
            ->selectRaw('(SELECT COUNT(*) FROM `vehicles` v WHERE v.`driver_id` = `d`.`driver_id` AND v.`deleted_at` IS NULL) AS `vehicle_count`')
            ->selectRaw('(SELECT GROUP_CONCAT(v.`plate_number` ORDER BY v.`plate_number` SEPARATOR ", ")
                            FROM `vehicles` v WHERE v.`driver_id` = `d`.`driver_id` AND v.`deleted_at` IS NULL) AS `plate_numbers`')
            ->selectRaw('`fp`.`template_number` AS `fingerprint_number`')
            ->selectRaw('`fp`.`status` AS `fingerprint_status`')
            ->leftJoin('fingerprint_templates', 'fp.template_id', 'd.fingerprint_template_id', 'fp')
            ->whereNull('d.deleted_at');
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->withVehicles();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike(
                ['d.full_name', 'd.driver_code', 'd.contact_number', 'd.email', 'd.government_id'],
                (string) $filters['search']
            );
        }

        foreach (['status' => 'd.status', 'owner_id' => 'd.owner_id'] as $filter => $column) {
            if (($filters[$filter] ?? '') !== '' && ($filters[$filter] ?? null) !== null) {
                $query->whereEquals($column, $filters[$filter]);
            }
        }

        // Licences due to expire are the ones an administrator must chase.
        if (($filters['licence'] ?? '') === 'expiring') {
            $query->whereNotNull('d.licence_expiry')
                  ->where('d.licence_expiry', '<=', now()->modify('+60 days')->format('Y-m-d'));
        } elseif (($filters['licence'] ?? '') === 'expired') {
            $query->whereNotNull('d.licence_expiry')
                  ->where('d.licence_expiry', '<', now()->format('Y-m-d'));
        }

        if (($filters['fingerprint'] ?? '') === 'enrolled') {
            $query->whereNotNull('d.fingerprint_template_id');
        } elseif (($filters['fingerprint'] ?? '') === 'not_enrolled') {
            $query->whereNull('d.fingerprint_template_id');
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
        $query->orderBy('d.' . $this->assertSortable((string) ($options['sort'] ?? 'full_name')), (string) ($options['direction'] ?? 'ASC'));

        return Paginator::fromQuery(
            $query,
            max(1, (int) ($options['page'] ?? 1)),
            (int) ($options['per_page'] ?? config('app.pagination.default_per_page', 25))
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findWithDetail(int $driverId): ?array
    {
        return $this->withVehicles()->whereEquals('d.driver_id', $driverId)->first();
    }

    /**
     * Vehicles a driver is assigned to.
     *
     * @return list<array<string,mixed>>
     */
    public function vehicles(int $driverId): array
    {
        return (new QueryBuilder($this->connection))
            ->table('v_vehicle_directory')
            ->whereEquals('driver_id', $driverId)
            ->whereNull('deleted_at')
            ->orderBy('plate_number')
            ->get();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function selectList(): array
    {
        return $this->query()
            ->select(['driver_id', 'full_name', 'driver_code'])
            ->whereEquals('status', 'active')
            ->orderBy('full_name')
            ->get();
    }

    /**
     * @return array<string,int>
     */
    public function statusCounts(): array
    {
        $rows = $this->connection->select(
            'SELECT `status`, COUNT(*) AS `total` FROM `drivers` WHERE `deleted_at` IS NULL GROUP BY `status`'
        );

        $counts = ['active' => 0, 'inactive' => 0, 'suspended' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Busiest drivers in a period.
     *
     * @return list<array<string,mixed>>
     */
    public function mostActive(string $from, string $to, int $limit = 10): array
    {
        return $this->connection->select(
            'SELECT d.`driver_id`, d.`full_name`, COUNT(l.`access_log_id`) AS `visits`
               FROM `drivers` d
               INNER JOIN `vehicle_access_logs` l ON l.`driver_id` = d.`driver_id`
                     AND l.`entry_time` BETWEEN :from AND :to
              GROUP BY d.`driver_id`, d.`full_name`
              ORDER BY `visits` DESC
              LIMIT ' . max(1, $limit),
            ['from' => $from, 'to' => $to]
        );
    }

    public function nextCode(): string
    {
        /*
         * Only codes that are the prefix followed by digits count.
         * A hand-entered code such as "DRV-TEST01" is not part of the
         * sequence, and ordering by length would otherwise pick it as
         * the highest, read its sequence as zero, and hand back a code
         * that already exists.
         */
        $highest = (int) $this->connection->scalar(
            "SELECT MAX(CAST(SUBSTRING(`driver_code`, 5) AS UNSIGNED))
               FROM `drivers`
              WHERE `driver_code` REGEXP '^DRV-[0-9]+$'"
        );

        $sequence = $highest;

        return sprintf('DRV-%04d', $sequence + 1);
    }
}
