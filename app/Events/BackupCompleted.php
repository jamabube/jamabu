<?php

declare(strict_types=1);

namespace App\Events;

/**
 * Raised when a backup finishes, successfully or not.
 *
 * @package App\Events
 * @version 1.0.0
 */
final readonly class BackupCompleted
{
    public function __construct(
        public int $backupId,
        public string $filename,
        public int $fileSize,
        public bool $successful,
        public string $message = ''
    ) {
    }
}
