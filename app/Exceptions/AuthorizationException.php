<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised when an authenticated principal lacks the permission required by the
 * operation. Every occurrence is recorded as a security event.
 */
class AuthorizationException extends VamsException
{
    protected string $errorCode = 'FORBIDDEN';
    protected int $statusCode = 403;
    protected string $severity = 'warning';

    private string $permission = '';

    protected function defaultMessage(): string
    {
        return 'You do not have permission to perform this action.';
    }

    public static function forPermission(string $permission): self
    {
        $exception = new self();
        $exception->permission = $permission;
        $exception->context = ['required_permission' => $permission];

        return $exception;
    }

    public function permission(): string
    {
        return $this->permission;
    }
}
