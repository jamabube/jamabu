<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised when submitted input fails validation.
 *
 * Carries a field => messages map that the API returns as "details" and the
 * web layer flashes back to the form.
 */
class ValidationException extends VamsException
{
    protected string $errorCode = 'VALIDATION_FAILED';
    protected int $statusCode = 422;
    protected string $severity = 'notice';

    /** @var array<string,list<string>> */
    private array $errors;

    /**
     * @param array<string,list<string>> $errors
     */
    public function __construct(array $errors, string $message = 'The submitted data is invalid.')
    {
        $this->errors = $errors;
        parent::__construct($message, ['errors' => $errors]);
    }

    /**
     * @return array<string,list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * The first message recorded for any field, useful for toast notifications.
     */
    public function firstMessage(): string
    {
        foreach ($this->errors as $messages) {
            if ($messages !== []) {
                return $messages[0];
            }
        }

        return $this->getMessage();
    }
}
