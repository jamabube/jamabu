<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised when a request is not authenticated, or credentials are rejected.
 *
 * The message is deliberately generic: the system never discloses whether the
 * username or the password was the incorrect half of a credential pair.
 */
class AuthenticationException extends VamsException
{
    protected string $errorCode = 'UNAUTHENTICATED';
    protected int $statusCode = 401;
    protected string $severity = 'warning';

    protected function defaultMessage(): string
    {
        return 'Authentication is required to perform this action.';
    }

    public static function invalidCredentials(): self
    {
        $exception = new self('The credentials provided are incorrect.');
        $exception->errorCode = 'INVALID_CREDENTIALS';

        return $exception;
    }

    public static function accountLocked(int $minutesRemaining = 0): self
    {
        $message = $minutesRemaining > 0
            ? sprintf('This account is temporarily locked. Try again in %d minute(s).', $minutesRemaining)
            : 'This account is locked. Contact a system administrator.';

        $exception = new self($message, ['minutes_remaining' => $minutesRemaining]);
        $exception->errorCode = 'ACCOUNT_LOCKED';
        $exception->statusCode = 423;

        return $exception;
    }

    public static function accountInactive(): self
    {
        $exception = new self('This account is not active. Contact a system administrator.');
        $exception->errorCode = 'ACCOUNT_INACTIVE';
        $exception->statusCode = 403;

        return $exception;
    }

    public static function sessionExpired(): self
    {
        $exception = new self('Your session has expired. Please sign in again.');
        $exception->errorCode = 'SESSION_EXPIRED';

        return $exception;
    }

    public static function passwordExpired(): self
    {
        $exception = new self('Your password has expired and must be changed.');
        $exception->errorCode = 'PASSWORD_EXPIRED';
        $exception->statusCode = 403;

        return $exception;
    }
}
