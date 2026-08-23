<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;
use App\Core\Support\Str;

/**
 * Permanent RFID windshield tags.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class RfidTagRepository extends BaseRepository
{
    protected string $table = 'rfid_tags';
    protected string $primaryKey = 'rfid_tag_id';

    protected array $fillable = [
        'rfid_uid', 'tag_code', 'tag_type', 'frequency', 'serial_number', 'status',
        'activation_date', 'expiration_date', 'remarks', 'created_by', 'updated_by',
    ];

    protected array $sortable = [
        'tag_code', 'rfid_uid', 'status', 'activation_date', 'expiration_date',
        'last_scanned_at', 'scan_count', 'created_at',
    ];

    protected array $searchable = ['rfid_uid', 'tag_code', 'serial_number'];

    /**
     * Tags with the vehicle each is attached to.
     */
    public function withAssignment(): QueryBuilder
    {
        return (new QueryBuilder($this->connection))
            ->table('rfid_tags', 't')
            ->select([
                't.rfid_tag_id', 't.rfid_uid', 't.tag_code', 't.tag_type', 't.frequency',
                't.serial_number', 't.status', 't.activation_date', 't.expiration_date',
                't.last_scanned_at', 't.scan_count', 't.remarks', 't.created_at',
            ])
            ->selectRaw('`v`.`vehicle_id` AS `vehicle_id`')
            ->selectRaw('`v`.`plate_number` AS `plate_number`')
            ->selectRaw('`v`.`status` AS `vehicle_status`')
            ->selectRaw('`o`.`full_name` AS `owner_name`')
            ->selectRaw('`d`.`full_name` AS `driver_name`')
            ->leftJoin('vehicles', 'v.rfid_tag_id', 't.rfid_tag_id', 'v')
            ->leftJoin('vehicle_owners', 'o.owner_id', 'v.owner_id', 'o')
            ->leftJoin('drivers', 'd.driver_id', 'v.driver_id', 'd')
            ->whereNull('t.deleted_at');
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByUid(string $uid): ?array
    {
        return $this->findBy('rfid_uid', Str::normaliseUid($uid));
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->withAssignment();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike(
                ['t.rfid_uid', 't.tag_code', 't.serial_number', 'v.plate_number', 'o.full_name'],
                (string) $filters['search']
            );
        }

        if (($filters['status'] ?? '') !== '') {
            $query->whereEquals('t.status', (string) $filters['status']);
        }

        if (($filters['tag_type'] ?? '') !== '') {
            $query->whereEquals('t.tag_type', (string) $filters['tag_type']);
        }

        if (($filters['assignment'] ?? '') === 'assigned') {
            $query->whereNotNull('v.vehicle_id');
        } elseif (($filters['assignment'] ?? '') === 'unassigned') {
            $query->whereNull('v.vehicle_id');
        }

        if (($filters['expiring'] ?? false) === true) {
            $query->whereNotNull('t.expiration_date')
                  ->where('t.expiration_date', '<=', now()->modify('+30 days')->format('Y-m-d'));
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
        $query->orderBy('t.' . $this->assertSortable((string) ($options['sort'] ?? 'tag_code')), (string) ($options['direction'] ?? 'ASC'));

        return Paginator::fromQuery(
            $query,
            max(1, (int) ($options['page'] ?? 1)),
            (int) ($options['per_page'] ?? config('app.pagination.default_per_page', 25))
        );
    }

    /**
     * Record that a tag was read.
     */
    public function recordScan(int $tagId): void
    {
        $this->connection->execute(
            'UPDATE `rfid_tags` SET `last_scanned_at` = :now, `scan_count` = `scan_count` + 1 WHERE `rfid_tag_id` = :id',
            ['now' => $this->timestamp(), 'id' => $tagId]
        );
    }

    /**
     * Tags available to be attached to a vehicle.
     *
     * @return list<array<string,mixed>>
     */
    public function availableForAssignment(?int $includeTagId = null): array
    {
        $query = $this->withAssignment()
            ->whereIn('t.status', ['available', 'assigned'])
            ->whereNull('v.vehicle_id');

        $available = $query->orderBy('t.tag_code')->get();

        // The tag already attached to the record being edited must remain
        // selectable, or saving the form would silently detach it.
        if ($includeTagId !== null) {
            $current = $this->withAssignment()->whereEquals('t.rfid_tag_id', $includeTagId)->first();

            if ($current !== null) {
                array_unshift($available, $current);
            }
        }

        return $available;
    }

    /**
     * Mark tags whose expiry has passed.
     */
    public function expireOverdue(): int
    {
        return $this->connection->execute(
            "UPDATE `rfid_tags`
                SET `status` = 'expired', `updated_at` = :now
              WHERE `status` IN ('available', 'assigned')
                AND `expiration_date` IS NOT NULL
                AND `expiration_date` < :today",
            ['now' => $this->timestamp(), 'today' => now()->format('Y-m-d')]
        );
    }

    /**
     * @return array<string,int>
     */
    public function statusCounts(): array
    {
        $rows = $this->connection->select(
            'SELECT `status`, COUNT(*) AS `total` FROM `rfid_tags` WHERE `deleted_at` IS NULL GROUP BY `status`'
        );

        $counts = [
            'available' => 0, 'assigned' => 0, 'inactive' => 0,
            'lost' => 0, 'damaged' => 0, 'expired' => 0, 'revoked' => 0,
        ];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Most-used tags in a period.
     *
     * @return list<array<string,mixed>>
     */
    public function mostUsed(string $from, string $to, int $limit = 10): array
    {
        return $this->connection->select(
            'SELECT t.`tag_code`, t.`rfid_uid`, v.`plate_number`, COUNT(l.`access_log_id`) AS `scans`
               FROM `rfid_tags` t
               LEFT JOIN `vehicles` v ON v.`rfid_tag_id` = t.`rfid_tag_id`
               INNER JOIN `vehicle_access_logs` l ON l.`rfid_tag_id` = t.`rfid_tag_id`
                     AND l.`entry_time` BETWEEN :from AND :to
              GROUP BY t.`rfid_tag_id`, t.`tag_code`, t.`rfid_uid`, v.`plate_number`
              ORDER BY `scans` DESC
              LIMIT ' . max(1, $limit),
            ['from' => $from, 'to' => $to]
        );
    }

    public function nextCode(): string
    {
        /*
         * Only codes that are the prefix followed by digits count.
         * A hand-entered code such as "TAG-TEST01" is not part of the
         * sequence, and ordering by length would otherwise pick it as
         * the highest, read its sequence as zero, and hand back a code
         * that already exists.
         */
        $highest = (int) $this->connection->scalar(
            "SELECT MAX(CAST(SUBSTRING(`tag_code`, 5) AS UNSIGNED))
               FROM `rfid_tags`
              WHERE `tag_code` REGEXP '^TAG-[0-9]+$'"
        );

        $sequence = $highest;

        return sprintf('TAG-%04d', $sequence + 1);
    }
}
