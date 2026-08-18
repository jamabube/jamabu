<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised when a route, page or record does not exist.
 */
class NotFoundException extends VamsException
{
    protected string $errorCode = 'NOT_FOUND';
    protected int $statusCode = 404;
    protected string $severity = 'notice';

    protected function defaultMessage(): string
    {
        return 'The requested resource could not be found.';
    }

    public static function record(string $entity, int|string $identifier): self
    {
        $exception = new self(sprintf('%s could not be found.', $entity));
        $exception->context = ['entity' => $entity, 'identifier' => $identifier];

        return $exception;
    }
}
