<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised when an identity exceeds its configured request budget.
 */
class RateLimitException extends VamsException
{
    protected string $errorCode = 'RATE_LIMIT_EXCEEDED';
    protected int $statusCode = 429;
    protected string $severity = 'warning';

    private int $retryAfter;

    public function __construct(int $retryAfter = 60, string $message = '')
    {
        $this->retryAfter = max(1, $retryAfter);

        parent::__construct(
            $message === '' ? 'Too many requests. Please slow down and try again shortly.' : $message,
            ['retry_after' => $this->retryAfter]
        );
    }

    public function retryAfter(): int
    {
        return $this->retryAfter;
    }

    public static function flood(int $retryAfter): self
    {
        $exception = new self($retryAfter, 'Request flooding detected. This source has been temporarily blocked.');
        $exception->errorCode = 'FLOOD_DETECTED';
        $exception->severity  = 'critical';

        return $exception;
    }
}
