<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Raised when a vehicle or visitor is granted exit.
 *
 * @package App\Events
 * @version 1.0.0
 */
final readonly class VehicleExited
{
    public function __construct(
        public int $accessLogId,
        public ?int $vehicleId,
        public string $plateNumber,
        public string $ownerName,
        public int $deviceId,
        public string $deviceCode,
        public string $occurredAt,
        public int $durationSeconds = 0,
        public bool $isVisitor = false
    ) {
    }
}
