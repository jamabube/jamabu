<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised when an IoT device fails any stage of the authentication pipeline.
 *
 * Every factory method below corresponds to one validation step described in
 * the device-authentication specification. The messages returned to the device
 * are intentionally terse so that an attacker probing the endpoint learns
 * nothing about which registered devices exist.
 */
class DeviceAuthenticationException extends VamsException
{
    protected string $errorCode = 'DEVICE_UNAUTHORIZED';
    protected int $statusCode = 401;
    protected string $severity = 'warning';

    /** Security-event type recorded for this failure. */
    private string $eventType = 'device_authentication_failure';

    protected function defaultMessage(): string
    {
        return 'Device authentication failed.';
    }

    public function eventType(): string
    {
        return $this->eventType;
    }

    private static function make(string $errorCode, string $eventType, string $severity, int $status = 401): self
    {
        $exception = new self();
        $exception->errorCode = $errorCode;
        $exception->eventType = $eventType;
        $exception->severity  = $severity;
        $exception->statusCode = $status;

        return $exception;
    }

    public static function missingCredentials(): self
    {
        return self::make('DEVICE_CREDENTIALS_MISSING', 'device_credentials_missing', 'warning');
    }

    public static function unknownDevice(string $deviceCode): self
    {
        $exception = self::make('DEVICE_UNKNOWN', 'unknown_device', 'critical');
        $exception->context = ['device_code' => $deviceCode];

        return $exception;
    }

    public static function invalidApiKey(string $deviceCode): self
    {
        $exception = self::make('DEVICE_INVALID_API_KEY', 'invalid_api_key', 'critical');
        $exception->context = ['device_code' => $deviceCode];

        return $exception;
    }

    public static function inactiveDevice(string $deviceCode): self
    {
        $exception = self::make('DEVICE_INACTIVE', 'inactive_device', 'warning', 403);
        $exception->context = ['device_code' => $deviceCode];

        return $exception;
    }

    public static function suspendedDevice(string $deviceCode): self
    {
        $exception = self::make('DEVICE_SUSPENDED', 'suspended_device', 'critical', 403);
        $exception->context = ['device_code' => $deviceCode];

        return $exception;
    }

    public static function staleTimestamp(int $skewSeconds): self
    {
        $exception = self::make('DEVICE_TIMESTAMP_INVALID', 'stale_timestamp', 'warning');
        $exception->context = ['skew_seconds' => $skewSeconds];

        return $exception;
    }

    public static function replayDetected(string $nonce): self
    {
        $exception = self::make('DEVICE_REPLAY_DETECTED', 'replay_attack', 'critical');
        $exception->context = ['nonce' => $nonce];

        return $exception;
    }

    public static function invalidSignature(): self
    {
        return self::make('DEVICE_SIGNATURE_INVALID', 'invalid_signature', 'critical');
    }

    public static function firmwareNotAllowed(string $firmware): self
    {
        $exception = self::make('DEVICE_FIRMWARE_REJECTED', 'firmware_rejected', 'warning', 403);
        $exception->context = ['firmware_version' => $firmware];

        return $exception;
    }

    public static function addressNotAllowed(string $ipAddress): self
    {
        $exception = self::make('DEVICE_IP_REJECTED', 'device_ip_rejected', 'critical', 403);
        $exception->context = ['ip_address' => $ipAddress];

        return $exception;
    }
}
