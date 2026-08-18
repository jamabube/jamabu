<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Base class for every exception raised by the application.
 *
 * Carrying a stable, machine-readable error code and a "safe" message lets the
 * HTTP layer answer a client without ever leaking internal detail, while the
 * full exception is still written to the error log for administrators.
 *
 * @package App\Exceptions
 * @version 1.0.0
 */
class VamsException extends RuntimeException
{
    /** Stable machine-readable identifier, e.g. "INVALID_RFID". */
    protected string $errorCode = 'INTERNAL_ERROR';

    /** HTTP status the HTTP layer should answer with. */
    protected int $statusCode = 500;

    /** Severity used when the exception reaches the error log. */
    protected string $severity = 'error';

    /** @var array<string,mixed> Structured, non-sensitive detail for the client. */
    protected array $details = [];

    /** @var array<string,mixed> Diagnostic context recorded in the log only. */
    protected array $context = [];

    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        string $message = '',
        array $details = [],
        int $code = 0,
        ?Throwable $previous = null
    ) {
        parent::__construct($message === '' ? $this->defaultMessage() : $message, $code, $previous);
        $this->details = $details;
    }

    protected function defaultMessage(): string
    {
        return 'An unexpected error occurred.';
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function severity(): string
    {
        return $this->severity;
    }

    /**
     * @return array<string,mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * @return array<string,mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    /**
     * Attach diagnostic context that must never reach the client.
     *
     * @param array<string,mixed> $context
     */
    public function withContext(array $context): static
    {
        $this->context = array_merge($this->context, $context);

        return $this;
    }

    /**
     * The message that is safe to show an end user.
     *
     * Subclasses that describe an expected business condition return their real
     * message; unexpected failures fall back to a generic sentence.
     */
    public function safeMessage(): string
    {
        return $this->getMessage();
    }
}
