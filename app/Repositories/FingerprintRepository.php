<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * Biometric enrolment metadata.
 *
 * Stores which sensor slot holds an enrolment and who it belongs to. No
 * fingerprint image and no reconstructable template is ever written here — the
 * checksum is a one-way digest of the signature the sensor reported, used only
 * to notice that a slot has been overwritten.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class FingerprintRepository extends BaseRepository
{
    protected string $table = 'fingerprint_templates';
    protected string $primaryKey = 'template_id';

    protected array $fillable = [
        'template_number', 'device_id', 'sensor_slot', 'finger_label', 'assigned_user_id',
        'assigned_driver_id', 'checksum', 'quality_score', 'enrolled_at', 'enrolled_by',
        'synchronised_at', 'status', 'remarks',
    ];

    protected array $sortable = ['template_number', 'sensor_slot', 'enrolled_at', 'last_verified_at', 'status'];
    protected array $searchable = ['template_number', 'finger_label'];

    /**
     * Enrolments with the person and device they belong to.
     */
    public function withHolder(): QueryBuilder
    {
        return (new QueryBuilder($this->connection))
            ->table('fingerprint_templates', 'f')
            ->select([
                'f.template_id', 'f.template_number', 'f.sensor_slot', 'f.finger_label',
                'f.quality_score', 'f.enrolled_at', 'f.last_verified_at', 'f.verification_count',
                'f.failure_count', 'f.synchronised_at', 'f.status', 'f.remarks',
                'f.assigned_user_id', 'f.assigned_driver_id', 'f.device_id',
            ])
            ->selectRaw('`u`.`full_name` AS `user_name`')
            ->selectRaw('`u`.`username` AS `username`')
            ->selectRaw('`dr`.`full_name` AS `driver_name`')
            ->selectRaw('`dv`.`device_name` AS `device_name`')
            ->selectRaw('`en`.`full_name` AS `enrolled_by_name`')
            ->leftJoin('users', 'u.user_id', 'f.assigned_user_id', 'u')
            ->leftJoin('drivers', 'dr.driver_id', 'f.assigned_driver_id', 'dr')
            ->leftJoin('devices', 'dv.device_id', 'f.device_id', 'dv')
            ->leftJoin('users', 'en.user_id', 'f.enrolled_by', 'en')
            ->whereNull('f.deleted_at');
    }

    /**
     * Resolve the enrolment a sensor slot maps to.
     *
     * @return array<string,mixed>|null
     */
    public function findBySlot(int $deviceId, int $sensorSlot): ?array
    {
        return $this->query()
            ->whereEquals('device_id', $deviceId)
            ->whereEquals('sensor_slot', $sensorSlot)
            ->whereEquals('status', 'active')
            ->first();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findForUser(int $userId): ?array
    {
        return $this->query()
            ->whereEquals('assigned_user_id', $userId)
            ->whereEquals('status', 'active')
            ->first();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findWithHolder(int $templateId): ?array
    {
        return $this->withHolder()->whereEquals('f.template_id', $templateId)->first();
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->withHolder();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike(
                ['f.template_number', 'u.full_name', 'dr.full_name', 'u.username'],
                (string) $filters['search']
            );
        }

        foreach (['status' => 'f.status', 'device_id' => 'f.device_id'] as $filter => $column) {
            if (($filters[$filter] ?? '') !== '' && ($filters[$filter] ?? null) !== null) {
                $query->whereEquals($column, $filters[$filter]);
            }
        }

        if (($filters['holder'] ?? '') === 'user') {
            $query->whereNotNull('f.assigned_user_id');
        } elseif (($filters['holder'] ?? '') === 'driver') {
            $query->whereNotNull('f.assigned_driver_id');
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
        $query->orderBy('f.' . $this->assertSortable((string) ($options['sort'] ?? 'template_number')), (string) ($options['direction'] ?? 'ASC'));

        return Paginator::fromQuery(
            $query,
            max(1, (int) ($options['page'] ?? 1)),
            (int) ($options['per_page'] ?? config('app.pagination.default_per_page', 25))
        );
    }

    /**
     * The next unused slot on a sensor.
     *
     * Slots are a finite hardware resource, so the lowest free one is reused
     * rather than always taking the next number.
     */
    public function nextAvailableSlot(int $deviceId, int $capacity = 1000): int
    {
        $used = array_map(intval(...), $this->connection->column(
            'SELECT `sensor_slot` FROM `fingerprint_templates`
              WHERE `device_id` = ? AND `deleted_at` IS NULL
              ORDER BY `sensor_slot`',
            [$deviceId]
        ));

        for ($slot = 1; $slot <= $capacity; $slot++) {
            if (!in_array($slot, $used, true)) {
                return $slot;
            }
        }

        return 0; // The sensor is full.
    }

    public function recordVerification(int $templateId, bool $successful): void
    {
        $column = $successful ? 'verification_count' : 'failure_count';

        $this->connection->execute(
            sprintf(
                'UPDATE `fingerprint_templates`
                    SET `%s` = `%s` + 1, `last_verified_at` = :now
                  WHERE `template_id` = :id',
                $column,
                $column
            ),
            ['now' => $this->timestamp(), 'id' => $templateId]
        );
    }

    public function markSynchronised(int $templateId): void
    {
        $this->connection->execute(
            "UPDATE `fingerprint_templates`
                SET `synchronised_at` = :now, `status` = 'active', `updated_at` = :now
              WHERE `template_id` = :id",
            ['now' => $this->timestamp(), 'id' => $templateId]
        );
    }

    /**
     * @return array<string,int>
     */
    public function statusCounts(): array
    {
        $rows = $this->connection->select(
            'SELECT `status`, COUNT(*) AS `total` FROM `fingerprint_templates` WHERE `deleted_at` IS NULL GROUP BY `status`'
        );

        $counts = ['active' => 0, 'inactive' => 0, 'pending_sync' => 0, 'revoked' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function nextTemplateNumber(): string
    {
        $highest = (string) $this->connection->scalar(
            "SELECT `template_number` FROM `fingerprint_templates`
              WHERE `template_number` LIKE 'FP-%'
              ORDER BY LENGTH(`template_number`) DESC, `template_number` DESC
              LIMIT 1"
        );

        $sequence = $highest === '' ? 0 : (int) substr($highest, 3);

        return sprintf('FP-%06d', $sequence + 1);
    }
}
