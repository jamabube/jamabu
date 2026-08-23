<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;
use App\Core\Support\Str;

/**
 * Registered vehicle storage.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class VehicleRepository extends BaseRepository
{
    protected string $table = 'vehicles';
    protected string $primaryKey = 'vehicle_id';

    protected array $fillable = [
        'vehicle_code', 'plate_number', 'rfid_tag_id', 'vehicle_type_id', 'brand', 'model',
        'colour', 'year_model', 'chassis_number', 'engine_number', 'owner_id', 'driver_id',
        'registration_date', 'insurance_provider', 'insurance_expiry', 'photo', 'status',
        'remarks', 'created_by', 'updated_by', 'deleted_by',
    ];

    protected array $sortable = [
        'plate_number', 'vehicle_code', 'brand', 'model', 'status', 'created_at',
        'owner_name', 'driver_name', 'vehicle_type', 'presence', 'registration_date',
    ];

    protected array $searchable = [
        'plate_number', 'vehicle_code', 'brand', 'model', 'colour',
        'owner_name', 'driver_name', 'rfid_uid', 'tag_code',
    ];

    /**
     * The directory view, carrying owner, driver, type, tag and presence.
     */
    public function directory(): QueryBuilder
    {
        return (new QueryBuilder($this->connection))->table('v_vehicle_directory')->whereNull('deleted_at');
    }

    /**
     * Resolve a vehicle from a scanned RFID UID.
     *
     * This is the hot path of the whole system: it runs on every scan, so it is
     * a single indexed join rather than two round trips.
     *
     * @return array<string,mixed>|null
     */
    public function findByRfidUid(string $rfidUid): ?array
    {
        return $this->connection->selectOne(
            'SELECT v.*, t.`rfid_uid`, t.`tag_code`, t.`status` AS `tag_status`,
                    t.`expiration_date` AS `tag_expiration`, t.`rfid_tag_id` AS `tag_id`,
                    o.`full_name` AS `owner_name`, o.`contact_number` AS `owner_contact`,
                    d.`full_name` AS `driver_name`, d.`status` AS `driver_status`,
                    vt.`type_name` AS `vehicle_type`
               FROM `rfid_tags` t
               LEFT JOIN `vehicles` v ON v.`rfid_tag_id` = t.`rfid_tag_id` AND v.`deleted_at` IS NULL
               LEFT JOIN `vehicle_owners` o ON o.`owner_id` = v.`owner_id`
               LEFT JOIN `drivers` d ON d.`driver_id` = v.`driver_id`
               LEFT JOIN `vehicle_types` vt ON vt.`vehicle_type_id` = v.`vehicle_type_id`
              WHERE t.`rfid_uid` = :uid AND t.`deleted_at` IS NULL
              LIMIT 1',
            ['uid' => Str::normaliseUid($rfidUid)]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByPlate(string $plateNumber): ?array
    {
        return $this->findBy('plate_number', Str::normalisePlate($plateNumber));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findInDirectory(int $vehicleId): ?array
    {
        return $this->directory()->whereEquals('vehicle_id', $vehicleId)->first();
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->directory();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike($this->searchable, (string) $filters['search']);
        }

        foreach (['status', 'vehicle_type_id', 'owner_id', 'driver_id', 'presence'] as $column) {
            if (($filters[$column] ?? '') !== '' && ($filters[$column] ?? null) !== null) {
                $query->whereEquals($column, $filters[$column]);
            }
        }

        // Vehicles whose tag is missing, expired or disabled: the list an
        // administrator needs before those drivers are turned away at the gate.
        if (($filters['tag_state'] ?? '') === 'unassigned') {
            $query->whereNull('rfid_tag_id');
        } elseif (($filters['tag_state'] ?? '') === 'expiring') {
            $query->whereNotNull('tag_expiration')
                  ->where('tag_expiration', '<=', now()->modify('+30 days')->format('Y-m-d'));
        }

        $query->whereDateRange('created_at', $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        return $query;
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginate(array $filters, array $options): Paginator
    {
        $query = $this->filtered($filters);
        $query->orderBy($this->assertSortable((string) ($options['sort'] ?? 'plate_number')), (string) ($options['direction'] ?? 'ASC'));

        return Paginator::fromQuery(
            $query,
            max(1, (int) ($options['page'] ?? 1)),
            (int) ($options['per_page'] ?? config('app.pagination.default_per_page', 25))
        );
    }

    /**
     * Vehicles for a drop-down.
     *
     * @return list<array<string,mixed>>
     */
    public function selectList(): array
    {
        return $this->query()
            ->select(['vehicle_id', 'plate_number', 'vehicle_code'])
            ->whereEquals('status', 'active')
            ->orderBy('plate_number')
            ->get();
    }

    /**
     * Attach a tag to a vehicle, detaching it from any previous holder.
     */
    public function assignTag(int $vehicleId, ?int $rfidTagId, ?int $updatedBy): void
    {
        $this->connection->transaction(function () use ($vehicleId, $rfidTagId, $updatedBy): void {
            // The unique index would reject a second holder, so the previous
            // association is cleared first rather than relying on the error.
            if ($rfidTagId !== null) {
                $this->connection->execute(
                    'UPDATE `vehicles` SET `rfid_tag_id` = NULL WHERE `rfid_tag_id` = :tag AND `vehicle_id` <> :vehicle',
                    ['tag' => $rfidTagId, 'vehicle' => $vehicleId]
                );
            }

            $this->connection->execute(
                'UPDATE `vehicles` SET `rfid_tag_id` = :tag, `updated_by` = :by, `updated_at` = :now WHERE `vehicle_id` = :id',
                ['tag' => $rfidTagId, 'by' => $updatedBy, 'now' => $this->timestamp(), 'id' => $vehicleId]
            );
        });
    }

    /**
     * @return array<string,int>
     */
    public function statusCounts(): array
    {
        $rows = $this->connection->select(
            'SELECT `status`, COUNT(*) AS `total` FROM `vehicles` WHERE `deleted_at` IS NULL GROUP BY `status`'
        );

        $counts = ['active' => 0, 'inactive' => 0, 'suspended' => 0, 'archived' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Counts per vehicle type, for the registry breakdown chart.
     *
     * @return list<array<string,mixed>>
     */
    public function countsByType(): array
    {
        return $this->connection->select(
            "SELECT vt.`type_name`, vt.`icon`, COUNT(v.`vehicle_id`) AS `total`
               FROM `vehicle_types` vt
               LEFT JOIN `vehicles` v ON v.`vehicle_type_id` = vt.`vehicle_type_id`
                    AND v.`deleted_at` IS NULL AND v.`status` = 'active'
              WHERE vt.`status` = 'active'
              GROUP BY vt.`vehicle_type_id`, vt.`type_name`, vt.`icon`
              ORDER BY `total` DESC"
        );
    }

    /**
     * Generate the next vehicle code in the series.
     */
    /**
     * Active vehicles with no tag assigned.
     *
     * These are registered but unreadable at the gate, which is a different
     * problem from being inactive and needs to be visible as its own figure.
     */
    public function countUntagged(): int
    {
        return (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM `vehicles`
              WHERE `rfid_tag_id` IS NULL AND `status` = 'active' AND `deleted_at` IS NULL"
        );
    }

    public function nextCode(): string
    {
        /*
         * Only codes that are the prefix followed by digits count.
         * A hand-entered code such as "VEH-TEST01" is not part of the
         * sequence, and ordering by length would otherwise pick it as
         * the highest, read its sequence as zero, and hand back a code
         * that already exists.
         */
        $highest = (int) $this->connection->scalar(
            "SELECT MAX(CAST(SUBSTRING(`vehicle_code`, 5) AS UNSIGNED))
               FROM `vehicles`
              WHERE `vehicle_code` REGEXP '^VEH-[0-9]+$'"
        );

        $sequence = $highest;

        return sprintf('VEH-%04d', $sequence + 1);
    }
}
