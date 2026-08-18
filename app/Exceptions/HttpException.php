<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

/**
 * Generic HTTP error carrying an explicit status code.
 */
class HttpException extends VamsException
{
    /**
     * @param array<string,mixed> $details
     */
    public function __construct(
        int $statusCode,
        string $message = '',
        string $errorCode = 'HTTP_ERROR',
        array $details = [],
        ?Throwable $previous = null
    ) {
        $this->statusCode = $statusCode;
        $this->errorCode  = $errorCode;
        $this->severity   = $statusCode >= 500 ? 'error' : 'warning';

        parent::__construct($message, $details, 0, $previous);
    }

    protected function defaultMessage(): string
    {
        return 'The request could not be completed.';
    }
}
