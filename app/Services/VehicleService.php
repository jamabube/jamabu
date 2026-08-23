<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Connection;
use App\Core\Database\Paginator;
use App\Core\Support\Str;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Exceptions\DatabaseException;
use App\Exceptions\NotFoundException;
use App\Repositories\AccessLogRepository;
use App\Repositories\RfidTagRepository;
use App\Repositories\VehicleRepository;
use Throwable;

/**
 * Vehicle registry business logic.
 *
 * @package App\Services
 * @version 1.0.0
 */
class VehicleService
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly RfidTagRepository $tags,
        private readonly AccessLogRepository $accessLogs,
        private readonly AuditService $audit,
        private readonly Connection $connection
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginate(array $filters, array $options): Paginator
    {
        return $this->vehicles->paginate($filters, $options);
    }

    /**
     * Everything the detail page shows.
     *
     * @return array<string,mixed>
     *
     * @throws NotFoundException
     */
    public function detail(int $vehicleId): array
    {
        $vehicle = $this->vehicles->findInDirectory($vehicleId);

        if ($vehicle === null) {
            throw NotFoundException::record('Vehicle', $vehicleId);
        }

        return [
            'vehicle'  => $vehicle,
            'timeline' => $this->accessLogs->timelineForVehicle($vehicleId, 25),
            'presence' => (string) $vehicle['presence'],
            'statistics' => $this->statisticsFor($vehicleId),
        ];
    }

    /**
     * Visit statistics for one vehicle.
     *
     * @return array<string,mixed>
     */
    public function statisticsFor(int $vehicleId): array
    {
        $row = $this->connection->selectOne(
            'SELECT COUNT(*) AS `total_visits`,
                    ROUND(AVG(`duration_seconds`)) AS `average_stay_seconds`,
                    MAX(`entry_time`) AS `last_entry`,
                    MAX(`exit_time`) AS `last_exit`
               FROM `vehicle_access_logs`
              WHERE `vehicle_id` = :id',
            ['id' => $vehicleId]
        ) ?? [];

        return [
            'total_visits'         => (int) ($row['total_visits'] ?? 0),
            'average_stay_seconds' => (int) ($row['average_stay_seconds'] ?? 0),
            'last_entry'           => $row['last_entry'] ?? null,
            'last_exit'            => $row['last_exit'] ?? null,
        ];
    }

    /**
     * Register a vehicle.
     *
     * @param array<string,mixed> $attributes
     *
     * @throws ConflictException
     */
    public function create(array $attributes, ?int $actorId): int
    {
        $attributes['plate_number'] = Str::normalisePlate((string) ($attributes['plate_number'] ?? ''));
        $attributes['vehicle_code'] ??= $this->vehicles->nextCode();

        $this->assertPlateAvailable($attributes['plate_number'], null);

        $tagId = isset($attributes['rfid_tag_id']) && $attributes['rfid_tag_id'] !== ''
            ? (int) $attributes['rfid_tag_id']
            : null;

        if ($tagId !== null) {
            $this->assertTagAssignable($tagId, null);
        }

        // The vehicle row and the tag association are written together: a
        // vehicle registered without the tag its owner was told to expect is a
        // support call at the gate.
        try {
            $vehicleId = $this->connection->transaction(function () use ($attributes, $tagId, $actorId): int {
                $id = $this->vehicles->create(array_merge($attributes, [
                    'rfid_tag_id' => null,
                    'created_by'  => $actorId,
                    'updated_by'  => $actorId,
                ]));

                if ($tagId !== null) {
                    $this->vehicles->assignTag($id, $tagId, $actorId);
                    $this->tags->update($tagId, ['status' => 'assigned']);
                }

                return $id;
            });
        } catch (Throwable $e) {
            /*
             * The check above is not enough on its own: two operators
             * registering the same plate at the same moment can both pass it
             * and only one insert survives the unique index. The loser gets
             * the same conflict the check would have produced, rather than an
             * unhandled driver exception reaching the guardhouse as a stack
             * trace.
             */
            if ($this->isDuplicatePlate($e)) {
                throw ConflictException::duplicate('vehicle', 'plate number', (string) $attributes['plate_number']);
            }

            throw $e;
        }

        $this->audit->created('vehicles', 'vehicles', $vehicleId, sprintf(
            'Vehicle %s was registered.',
            $attributes['plate_number']
        ), $attributes);

        return $vehicleId;
    }

    /**
     * Update a vehicle.
     *
     * @param array<string,mixed> $attributes
     */
    public function update(int $vehicleId, array $attributes, ?int $actorId): void
    {
        $existing = $this->vehicles->findOrFail($vehicleId);

        if (isset($attributes['plate_number'])) {
            $attributes['plate_number'] = Str::normalisePlate((string) $attributes['plate_number']);
            $this->assertPlateAvailable($attributes['plate_number'], $vehicleId);
        }

        $tagId = array_key_exists('rfid_tag_id', $attributes)
            ? ($attributes['rfid_tag_id'] === '' || $attributes['rfid_tag_id'] === null ? null : (int) $attributes['rfid_tag_id'])
            : false;

        if (is_int($tagId)) {
            $this->assertTagAssignable($tagId, $vehicleId);
        }

        $this->connection->transaction(function () use ($vehicleId, $attributes, $tagId, $actorId, $existing): void {
            $this->vehicles->update($vehicleId, array_merge(
                array_diff_key($attributes, ['rfid_tag_id' => null]),
                ['updated_by' => $actorId]
            ));

            if ($tagId === false) {
                return;
            }

            // Releasing a tag returns it to the pool so it can be reissued.
            $previousTagId = $existing['rfid_tag_id'] === null ? null : (int) $existing['rfid_tag_id'];

            if ($previousTagId !== null && $previousTagId !== $tagId) {
                $this->tags->update($previousTagId, ['status' => 'available']);
            }

            $this->vehicles->assignTag($vehicleId, $tagId, $actorId);

            if ($tagId !== null) {
                $this->tags->update($tagId, ['status' => 'assigned']);
            }
        });

        $this->audit->updated('vehicles', 'vehicles', $vehicleId, sprintf(
            'Vehicle %s was updated.',
            (string) ($attributes['plate_number'] ?? $existing['plate_number'])
        ), $existing, $attributes);
    }

    /**
     * Deactivate a vehicle.
     *
     * @throws BusinessRuleException
     */
    public function deactivate(int $vehicleId, ?int $actorId): void
    {
        $vehicle = $this->vehicles->findOrFail($vehicleId);

        // Removing a vehicle that is inside would leave the premises count
        // wrong and the open visit unresolvable.
        if ($this->accessLogs->openVisitForVehicle($vehicleId) !== null) {
            throw BusinessRuleException::withCode(
                'VEHICLE_INSIDE',
                'This vehicle is currently inside the premises and cannot be deactivated until it leaves.'
            );
        }

        $this->connection->transaction(function () use ($vehicleId, $vehicle, $actorId): void {
            $this->vehicles->delete($vehicleId, $actorId);

            // The tag is a reusable asset and returns to the pool.
            if ($vehicle['rfid_tag_id'] !== null) {
                $this->tags->update((int) $vehicle['rfid_tag_id'], ['status' => 'available']);
            }
        });

        $this->audit->deleted('vehicles', 'vehicles', $vehicleId, sprintf(
            'Vehicle %s was deactivated.',
            (string) $vehicle['plate_number']
        ), ['plate_number' => $vehicle['plate_number'], 'status' => $vehicle['status']]);
    }

    public function restore(int $vehicleId, ?int $actorId): void
    {
        $this->vehicles->restore($vehicleId);
        $this->vehicles->update($vehicleId, ['status' => 'active', 'updated_by' => $actorId]);

        $this->audit->record('vehicles', 'restored', sprintf('Vehicle %d was restored.', $vehicleId), [
            'record_type' => 'vehicles',
            'record_id'   => $vehicleId,
        ]);
    }

    /**
     * @throws ConflictException
     */
    /**
     * Whether a throwable is the plate-uniqueness violation.
     *
     * The database is the only place that can settle this without a race, so
     * its verdict is translated rather than second-guessed.
     */
    private function isDuplicatePlate(Throwable $e): bool
    {
        $message = $e->getMessage();

        if ($e instanceof DatabaseException) {
            $message .= ' ' . (string) ($e->context()['driver_message'] ?? '');
        }

        return str_contains($message, 'uq_vehicles_plate');
    }

    private function assertPlateAvailable(string $plateNumber, ?int $exceptId): void
    {
        if ($this->vehicles->existsWhere('plate_number', $plateNumber, $exceptId)) {
            throw ConflictException::duplicate('vehicle', 'plate number', $plateNumber);
        }
    }

    /**
     * A tag may only be attached to one vehicle.
     *
     * @throws ConflictException
     * @throws BusinessRuleException
     */
    private function assertTagAssignable(int $tagId, ?int $forVehicleId): void
    {
        $tag = $this->tags->find($tagId);

        if ($tag === null) {
            throw NotFoundException::record('RFID tag', $tagId);
        }

        if (in_array((string) $tag['status'], ['lost', 'damaged', 'revoked', 'expired'], true)) {
            throw BusinessRuleException::withCode(
                'TAG_NOT_USABLE',
                sprintf('This tag is marked %s and cannot be assigned.', (string) $tag['status'])
            );
        }

        $holder = $this->vehicles->findBy('rfid_tag_id', $tagId);

        if ($holder !== null && (int) $holder['vehicle_id'] !== $forVehicleId) {
            throw ConflictException::duplicate('vehicle', 'RFID tag', (string) $tag['tag_code']);
        }
    }

    /**
     * Registry summary for the dashboard and the module header.
     *
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $counts = $this->vehicles->statusCounts();

        return [
            'total'    => array_sum($counts),
            'statuses' => $counts,
            'inside'   => $this->accessLogs->countInside(),
            'by_type'  => $this->vehicles->countsByType(),
            // An active vehicle with no tag cannot be read at the gate, so it
            // is counted separately rather than being lost inside "active".
            'untagged' => $this->vehicles->countUntagged(),
        ];
    }
}
