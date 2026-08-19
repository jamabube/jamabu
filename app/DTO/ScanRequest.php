<?php

declare(strict_types=1);

namespace App\DTO;

use App\Core\Support\Str;

/**
 * A single RFID scan submitted by a monitoring station.
 *
 * Normalising the UID here means every comparison downstream is against the
 * same canonical form, whatever separators or case the reader used.
 *
 * @package App\DTO
 * @version 1.0.0
 */
final readonly class ScanRequest
{
    public function __construct(
        public string $rfidUid,
        public string $accessType,
        public int $deviceId,
        public string $deviceCode,
        public string $verificationMethod = 'rfid',
        public ?int $operatorUserId = null,
        public ?int $operatorSessionId = null,
        public ?string $scannedAt = null,
        public string $requestId = '',
        public string $ipAddress = '',
        public ?string $remarks = null,
        /**
         * A movement recorded by a signed-in operator from the dashboard
         * rather than read by a station. It carries its own actor, so the
         * on-duty check that guards station traffic does not apply.
         */
        public bool $manual = false
    ) {
    }

    /**
     * Build from a validated request payload plus the authenticated device.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $device
     */
    public static function fromPayload(
        array $payload,
        array $device,
        string $accessType,
        string $requestId = '',
        string $ipAddress = ''
    ): self {
        return new self(
            rfidUid: Str::normaliseUid((string) ($payload['rfid_uid'] ?? '')),
            accessType: $accessType,
            deviceId: (int) $device['device_id'],
            deviceCode: (string) $device['device_code'],
            verificationMethod: (string) ($payload['verification_method'] ?? 'rfid'),
            operatorUserId: null,
            operatorSessionId: null,
            // A queued scan replayed after a network outage carries the moment
            // it actually happened, which must survive into the record.
            scannedAt: isset($payload['scanned_at']) && is_string($payload['scanned_at'])
                ? $payload['scanned_at']
                : null,
            requestId: $requestId,
            ipAddress: $ipAddress,
            remarks: isset($payload['remarks']) && is_string($payload['remarks'])
                ? mb_substr($payload['remarks'], 0, 500)
                : null,
            manual: false
        );
    }

    /**
     * Build a movement an operator is recording by hand, for the case a
     * station is out of service and the guardhouse must still keep the log
     * complete.
     *
     * @param array<string,mixed> $device
     */
    public static function manual(
        string $rfidUid,
        string $accessType,
        array $device,
        int $operatorUserId,
        ?string $occurredAt,
        string $requestId = '',
        string $ipAddress = '',
        ?string $remarks = null
    ): self {
        return new self(
            rfidUid: Str::normaliseUid($rfidUid),
            accessType: $accessType,
            deviceId: (int) $device['device_id'],
            deviceCode: (string) $device['device_code'],
            verificationMethod: 'manual',
            operatorUserId: $operatorUserId,
            operatorSessionId: null,
            scannedAt: $occurredAt,
            requestId: $requestId,
            ipAddress: $ipAddress,
            remarks: $remarks === null ? null : mb_substr($remarks, 0, 500),
            manual: true
        );
    }

    /**
     * The moment to record, resolved against the server clock.
     *
     * A device-supplied time is honoured only when it is plausible: in the past
     * and inside the queue-replay window. Anything else falls back to now, so a
     * station with a wrong clock cannot write a record dated next year.
     */
    public function occurredAt(): string
    {
        if ($this->scannedAt === null) {
            return now()->format('Y-m-d H:i:s');
        }

        $parsed = strtotime($this->scannedAt);

        if ($parsed === false) {
            return now()->format('Y-m-d H:i:s');
        }

        $maximumAge = (int) config('api.device.max_queue_replay_age', 86400);

        if ($parsed > time() || $parsed < time() - $maximumAge) {
            return now()->format('Y-m-d H:i:s');
        }

        return date('Y-m-d H:i:s', $parsed);
    }

    /**
     * Whether the device supplied its own timestamp, meaning this scan was
     * queued during an outage rather than happening just now.
     */
    public function isQueuedReplay(): bool
    {
        return $this->scannedAt !== null;
    }

    public function withOperator(?int $userId, ?int $sessionId): self
    {
        return new self(
            rfidUid: $this->rfidUid,
            accessType: $this->accessType,
            deviceId: $this->deviceId,
            deviceCode: $this->deviceCode,
            verificationMethod: $this->verificationMethod,
            operatorUserId: $userId,
            operatorSessionId: $sessionId,
            scannedAt: $this->scannedAt,
            requestId: $this->requestId,
            ipAddress: $this->ipAddress,
            remarks: $this->remarks,
            manual: $this->manual
        );
    }
}
