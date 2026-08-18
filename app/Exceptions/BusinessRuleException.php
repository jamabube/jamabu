<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised when a request is well-formed and authorised but violates a monitoring
 * business rule (duplicate entry, exit without entry, expired visitor pass...).
 *
 * These are expected operating conditions, not defects: the message is safe to
 * display on the guardhouse screen verbatim.
 */
class BusinessRuleException extends VamsException
{
    protected string $errorCode = 'BUSINESS_RULE_VIOLATION';
    protected int $statusCode = 409;
    protected string $severity = 'notice';

    protected function defaultMessage(): string
    {
        return 'The request conflicts with a system business rule.';
    }

    /**
     * @param array<string,mixed> $details
     */
    public static function withCode(string $errorCode, string $message, array $details = [], int $status = 409): self
    {
        $exception = new self($message, $details);
        $exception->errorCode  = $errorCode;
        $exception->statusCode = $status;

        return $exception;
    }
}
