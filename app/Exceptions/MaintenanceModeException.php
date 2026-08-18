<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised while the application is in maintenance mode and the requester does
 * not hold the maintenance bypass permission.
 */
class MaintenanceModeException extends VamsException
{
    protected string $errorCode = 'SERVICE_UNAVAILABLE';
    protected int $statusCode = 503;
    protected string $severity = 'notice';

    protected function defaultMessage(): string
    {
        return 'The system is undergoing scheduled maintenance. Please try again later.';
    }
}
