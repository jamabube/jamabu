<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Connection;
use App\Core\Database\Paginator;
use App\Core\Support\Str;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Repositories\DriverRepository;
use App\Repositories\RfidCardRepository;
use App\Repositories\RfidTagRepository;
use App\Repositories\VehicleOwnerRepository;
use App\Repositories\VehicleRepository;

/**
 * Owner, driver and RFID inventory administration.
 *
 * These three share one service because their rules are intertwined: a driver
 * belongs to an owner, a tag belongs to a vehicle, and releasing any of them
 * has to consider the others.
 *
 * @package App\Services
 * @version 1.0.0
 */
class RegistryService
{
    public function __construct(
        private readonly VehicleOwnerRepository $owners,
        private readonly DriverRepository $drivers,
        private readonly RfidTagRepository $tags,
        private readonly RfidCardRepository $cards,
        private readonly VehicleRepository $vehicles,
        private readonly AuditService $audit,
        private readonly Connection $connection
    ) {
    }

    // ------------------------------------------------------------------
    // Owners
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginateOwners(array $filters, array $options): Paginator
    {
        return $this->owners->paginate($filters, $options);
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function createOwner(array $attributes, ?int $actorId): int
    {
        $attributes['owner_code'] ??= $this->owners->nextCode();

        $ownerId = $this->owners->create(array_merge($attributes, [
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]));

        $this->audit->created('owners', 'vehicle_owners', $ownerId, sprintf(
            'Owner %s %s was registered.',
            (string) ($attributes['first_name'] ?? ''),
            (string) ($attributes['last_name'] ?? '')
        ), $attributes);

        return $ownerId;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function updateOwner(int $ownerId, array $attributes, ?int $actorId): void
    {
        $existing = $this->owners->findOrFail($ownerId);

        $this->owners->update($ownerId, array_merge($attributes, ['updated_by' => $actorId]));

        $this->audit->updated('owners', 'vehicle_owners', $ownerId, sprintf(
            'Owner %s was updated.',
            (string) $existing['full_name']
        ), $existing, $attributes);
    }

    /**
     * @throws BusinessRuleException
     */
    public function deactivateOwner(int $ownerId, ?int $actorId): void
    {
        $owner = $this->owners->findOrFail($ownerId);

        // A vehicle must always have an accountable owner, so the vehicles have
        // to be reassigned first rather than being silently orphaned.
        $vehicles = $this->owners->vehicles($ownerId);

        if ($vehicles !== []) {
            throw BusinessRuleException::withCode(
                'OWNER_HAS_VEHICLES',
                sprintf(
                    '%d vehicle(s) are registered to this owner. Reassign them before deactivating the record.',
                    count($vehicles)
                )
            );
        }

        $this->owners->delete($ownerId, $actorId);

        $this->audit->deleted('owners', 'vehicle_owners', $ownerId, sprintf(
            'Owner %s was deactivated.',
            (string) $owner['full_name']
        ), ['owner_code' => $owner['owner_code']]);
    }

    // ------------------------------------------------------------------
    // Drivers
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginateDrivers(array $filters, array $options): Paginator
    {
        return $this->drivers->paginate($filters, $options);
    }

    /**
     * @return array<string,mixed>
     */
    public function driverDetail(int $driverId): array
    {
        $driver = $this->drivers->findWithDetail($driverId);

        if ($driver === null) {
            throw NotFoundException::record('Driver', $driverId);
        }

        return [
            'driver'   => $driver,
            'vehicles' => $this->drivers->vehicles($driverId),
        ];
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function createDriver(array $attributes, ?int $actorId): int
    {
        $attributes['driver_code'] ??= $this->drivers->nextCode();

        $driverId = $this->drivers->create(array_merge($attributes, [
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]));

        $this->audit->created('drivers', 'drivers', $driverId, sprintf(
            'Driver %s %s was registered.',
            (string) ($attributes['first_name'] ?? ''),
            (string) ($attributes['last_name'] ?? '')
        ), $attributes);

        return $driverId;
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function updateDriver(int $driverId, array $attributes, ?int $actorId): void
    {
        $existing = $this->drivers->findOrFail($driverId);

        $this->drivers->update($driverId, array_merge($attributes, ['updated_by' => $actorId]));

        $this->audit->updated('drivers', 'drivers', $driverId, sprintf(
            'Driver %s was updated.',
            (string) $existing['full_name']
        ), $existing, $attributes);
    }

    public function deactivateDriver(int $driverId, ?int $actorId): void
    {
        $driver = $this->drivers->findOrFail($driverId);

        $this->connection->transaction(function () use ($driverId, $actorId): void {
            // Vehicles keep their registration but lose the driver assignment,
            // which is what "this person no longer drives for us" means.
            $this->connection->execute(
                'UPDATE `vehicles` SET `driver_id` = NULL, `updated_by` = :by WHERE `driver_id` = :driver',
                ['by' => $actorId, 'driver' => $driverId]
            );

            $this->drivers->delete($driverId, $actorId);
        });

        $this->audit->deleted('drivers', 'drivers', $driverId, sprintf(
            'Driver %s was deactivated and unassigned from their vehicles.',
            (string) $driver['full_name']
        ), ['driver_code' => $driver['driver_code']]);
    }

    // ------------------------------------------------------------------
    // RFID inventory
    // ------------------------------------------------------------------

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginateTags(array $filters, array $options): Paginator
    {
        return $this->tags->paginate($filters, $options);
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginateCards(array $filters, array $options): Paginator
    {
        return $this->cards->paginate($filters, $options);
    }

    /**
     * Add a windshield tag to the inventory.
     *
     * @param array<string,mixed> $attributes
     *
     * @throws ConflictException
     */
    public function registerTag(array $attributes, ?int $actorId): int
    {
        $uid = Str::normaliseUid((string) ($attributes['rfid_uid'] ?? ''));

        if ($uid === '') {
            throw new \App\Exceptions\ValidationException(['rfid_uid' => ['An RFID UID is required.']]);
        }

        // A UID must be unique across tags *and* cards: the reader cannot tell
        // them apart, so a collision would make one credential shadow the other.
        if ($this->tags->existsWhere('rfid_uid', $uid, null)) {
            throw ConflictException::duplicate('RFID tag', 'UID', $uid);
        }

        if ($this->cards->existsWhere('card_uid', $uid, null)) {
            throw ConflictException::duplicate('RFID credential', 'UID', $uid);
        }

        $attributes['tag_code'] ??= $this->tags->nextCode();

        $tagId = $this->tags->create(array_merge($attributes, [
            'rfid_uid'        => $uid,
            'status'          => (string) ($attributes['status'] ?? 'available'),
            'activation_date' => $attributes['activation_date'] ?? now()->format('Y-m-d'),
            'created_by'      => $actorId,
            'updated_by'      => $actorId,
        ]));

        $this->audit->created('rfid', 'rfid_tags', $tagId, sprintf(
            'RFID tag %s (%s) was added to the inventory.',
            (string) $attributes['tag_code'],
            $uid
        ), ['rfid_uid' => $uid, 'tag_code' => $attributes['tag_code']]);

        return $tagId;
    }

    /**
     * Add a visitor card to the inventory.
     *
     * @param array<string,mixed> $attributes
     *
     * @throws ConflictException
     */
    public function registerCard(array $attributes, ?int $actorId): int
    {
        $uid = Str::normaliseUid((string) ($attributes['card_uid'] ?? ''));

        if ($uid === '') {
            throw new \App\Exceptions\ValidationException(['card_uid' => ['A card UID is required.']]);
        }

        if ($this->cards->existsWhere('card_uid', $uid, null)) {
            throw ConflictException::duplicate('visitor card', 'UID', $uid);
        }

        if ($this->tags->existsWhere('rfid_uid', $uid, null)) {
            throw ConflictException::duplicate('RFID credential', 'UID', $uid);
        }

        $attributes['card_code'] ??= $this->cards->nextCode();

        $cardId = $this->cards->create(array_merge($attributes, [
            'card_uid'   => $uid,
            'status'     => (string) ($attributes['status'] ?? 'available'),
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]));

        $this->audit->created('rfid', 'rfid_cards', $cardId, sprintf(
            'Visitor card %s (%s) was added to the inventory.',
            (string) $attributes['card_code'],
            $uid
        ), ['card_uid' => $uid, 'card_code' => $attributes['card_code']]);

        return $cardId;
    }

    /**
     * Change a tag's state — lost, damaged, reactivated.
     *
     * @throws BusinessRuleException
     */
    public function setTagStatus(int $tagId, string $status, string $reason, ?int $actorId): void
    {
        $tag = $this->tags->findOrFail($tagId);

        $permitted = ['available', 'assigned', 'inactive', 'lost', 'damaged', 'expired', 'revoked'];

        if (!in_array($status, $permitted, true)) {
            throw BusinessRuleException::withCode('INVALID_STATUS', 'That is not a recognised tag state.');
        }

        $holder = $this->vehicles->findBy('rfid_tag_id', $tagId);

        $this->connection->transaction(function () use ($tagId, $status, $reason, $actorId, $holder): void {
            $this->tags->update($tagId, [
                'status'     => $status,
                'remarks'    => $reason,
                'updated_by' => $actorId,
            ]);

            // A tag taken out of service must not stay attached to a vehicle,
            // or that vehicle would keep failing at the gate with a confusing
            // message about the tag rather than an obvious "no tag assigned".
            if ($holder !== null && in_array($status, ['lost', 'damaged', 'revoked'], true)) {
                $this->vehicles->assignTag((int) $holder['vehicle_id'], null, $actorId);
            }
        });

        $this->audit->record('rfid', 'status_changed', sprintf(
            'RFID tag %s was marked %s: %s',
            (string) $tag['tag_code'],
            $status,
            $reason
        ), ['record_type' => 'rfid_tags', 'record_id' => $tagId]);
    }

    /**
     * @throws BusinessRuleException
     */
    public function setCardStatus(int $cardId, string $status, string $reason, ?int $actorId): void
    {
        $card = $this->cards->findOrFail($cardId);

        if ((string) $card['status'] === 'issued' && $status !== 'issued') {
            throw BusinessRuleException::withCode(
                'CARD_ISSUED',
                'This card is currently issued to a visitor. Close or revoke the pass before changing its state.'
            );
        }

        $this->cards->update($cardId, [
            'status'     => $status,
            'remarks'    => $reason,
            'updated_by' => $actorId,
        ]);

        $this->audit->record('rfid', 'status_changed', sprintf(
            'Visitor card %s was marked %s: %s',
            (string) $card['card_code'],
            $status,
            $reason
        ), ['record_type' => 'rfid_cards', 'record_id' => $cardId]);
    }

    /**
     * Mark tags whose expiry has passed. Run by the maintenance task.
     */
    public function expireOverdueTags(): int
    {
        $expired = $this->tags->expireOverdue();

        if ($expired > 0) {
            $this->audit->record('rfid', 'expired', sprintf(
                '%d RFID tag(s) passed their expiration date and were marked expired.',
                $expired
            ));
        }

        return $expired;
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        return [
            'tags'    => $this->tags->statusCounts(),
            'cards'   => $this->cards->statusCounts(),
            'owners'  => $this->owners->count(),
            'drivers' => $this->drivers->statusCounts(),
        ];
    }
}
