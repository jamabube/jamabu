<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Raised when a monitoring station stops reporting.
 *
 * @package App\Events
 * @version 1.0.0
 */
final readonly class DeviceWentOffline
{
    public function __construct(
        public int $deviceId,
        public string $deviceCode,
        public string $deviceName,
        public ?string $location,
        public int $secondsSinceHeartbeat
    ) {
    }
}
