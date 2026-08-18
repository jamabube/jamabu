<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised when a state-changing web request arrives without a valid CSRF token.
 */
class CsrfTokenException extends VamsException
{
    protected string $errorCode = 'CSRF_TOKEN_INVALID';
    protected int $statusCode = 419;
    protected string $severity = 'warning';

    protected function defaultMessage(): string
    {
        return 'The security token has expired or is invalid. Please reload the page and try again.';
    }
}
