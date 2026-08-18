<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Raised after a user successfully signs in.
 *
 * @package App\Events
 * @version 1.0.0
 */
final readonly class UserSignedIn
{
    public function __construct(
        public int $userId,
        public string $username,
        public string $ipAddress
    ) {
    }
}
