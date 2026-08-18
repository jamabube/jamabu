<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Raised when a scan is refused.
 *
 * @package App\Events
 * @version 1.0.0
 */
final readonly class AccessDenied
{
    public function __construct(
        public string $reasonCode,
        public string $message,
        public string $rfidUid,
        public int $deviceId,
        public string $deviceCode,
        public ?string $plateNumber = null
    ) {
    }
}
