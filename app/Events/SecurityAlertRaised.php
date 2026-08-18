<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Raised when a high or critical security event is recorded.
 *
 * @package App\Events
 * @version 1.0.0
 */
final readonly class SecurityAlertRaised
{
    public function __construct(
        public ?int $eventId,
        public string $eventType,
        public string $severity,
        public string $description
    ) {
    }
}
