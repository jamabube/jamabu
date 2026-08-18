<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Wraps a PDO failure so the driver-level message (which can contain SQL and
 * therefore schema detail) never reaches a client.
 */
class DatabaseException extends VamsException
{
    protected string $errorCode = 'DATABASE_ERROR';
    protected int $statusCode = 500;
    protected string $severity = 'critical';

    /**
     * @param array<string,mixed> $context
     */
    public static function fromThrowable(Throwable $previous, string $operation, array $context = []): self
    {
        $exception = new self(
            sprintf('Database operation "%s" failed.', $operation),
            [],
            (int) $previous->getCode(),
            $previous
        );

        $exception->context = array_merge($context, [
            'operation' => $operation,
            'driver_message' => $previous->getMessage(),
        ]);

        return $exception;
    }

    public function safeMessage(): string
    {
        return 'A database error prevented this operation from completing. The incident has been logged.';
    }
}
