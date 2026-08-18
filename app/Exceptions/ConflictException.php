<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised when an operation would violate a uniqueness guarantee, for example
 * assigning an RFID tag that already belongs to another vehicle.
 */
class ConflictException extends VamsException
{
    protected string $errorCode = 'CONFLICT';
    protected int $statusCode = 409;
    protected string $severity = 'notice';

    protected function defaultMessage(): string
    {
        return 'The operation conflicts with an existing record.';
    }

    public static function duplicate(string $entity, string $field, string $value): self
    {
        $exception = new self(sprintf('A %s with that %s already exists.', $entity, $field));
        $exception->errorCode = 'DUPLICATE_RECORD';
        $exception->context   = ['entity' => $entity, 'field' => $field, 'value' => $value];

        return $exception;
    }
}
