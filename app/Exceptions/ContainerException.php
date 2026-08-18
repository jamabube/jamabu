<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised when a service cannot be resolved out of the container. Always a
 * programming error, never something an end user can trigger.
 */
class ContainerException extends VamsException
{
    protected string $errorCode = 'CONTAINER_ERROR';
    protected int $statusCode = 500;
    protected string $severity = 'critical';

    public function safeMessage(): string
    {
        return 'A required system component could not be initialised.';
    }
}
