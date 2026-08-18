<?php

declare(strict_types=1);

namespace App\DTO;

/**
 * The outcome of an access request.
 *
 * Returned for both granted and refused scans so the station always receives a
 * structured answer it can display, rather than having to interpret an
 * exception.
 *
 * @package App\DTO
 * @version 1.0.0
 */
final readonly class AccessDecision
{
    /**
     * @param array<string,mixed> $data Detail for the station display.
     */
    private function __construct(
        public bool $granted,
        public string $resultCode,
        public string $message,
        public array $data = [],
        public ?int $accessLogId = null,
        public ?string $transactionReference = null,
        public bool $duplicateSuppressed = false
    ) {
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function granted(
        string $message,
        array $data,
        int $accessLogId,
        string $transactionReference
    ): self {
        return new self(true, 'granted', $message, $data, $accessLogId, $transactionReference);
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function denied(string $resultCode, string $message, array $data = []): self
    {
        return new self(false, $resultCode, $message, $data);
    }

    /**
     * A repeat read of a tag the station already reported.
     *
     * Acknowledged rather than refused: the station did nothing wrong, and
     * showing the guard an error for a second antenna read would be misleading.
     *
     * @param array<string,mixed> $data
     */
    public static function duplicateSuppressed(string $message, array $data, ?int $accessLogId, ?string $reference): self
    {
        return new self(true, 'granted', $message, $data, $accessLogId, $reference, true);
    }

    /**
     * The payload returned to the station.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'granted'               => $this->granted,
            'result'                => $this->resultCode,
            'message'               => $this->message,
            'access_log_id'         => $this->accessLogId,
            'transaction_reference' => $this->transactionReference,
            'duplicate_suppressed'  => $this->duplicateSuppressed,
        ], $this->data);
    }
}
