<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Raised when a station that had gone quiet reports in again.
 *
 * @package App\Events
 * @version 1.0.0
 */
final readonly class DeviceCameOnline
{
    public function __construct(
        public int $deviceId,
        public string $deviceCode,
        public string $deviceName,
        public int $outageSeconds
    ) {
    }
}
