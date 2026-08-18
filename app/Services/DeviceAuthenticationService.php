<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Http\Request;
use App\Core\Security\AuthGuard;
use App\Core\Security\Hasher;
use App\Core\Support\Str;
use App\Exceptions\DeviceAuthenticationException;
use App\Repositories\DeviceRepository;
use App\Repositories\NonceRepository;

/**
 * Authenticates an IoT device on every request it makes.
 *
 * The pipeline runs in a fixed order, cheapest and most decisive checks first:
 *
 *   1. Credentials present
 *   2. Device registered            (unknown devices are rejected outright)
 *   3. Device active and not suspended
 *   4. Source address permitted     (when the device is pinned to one)
 *   5. Firmware permitted
 *   6. API key correct              (constant-time comparison)
 *   7. Timestamp fresh              (bounds the replay window)
 *   8. Nonce unused                 (closes the replay window entirely)
 *   9. Signature valid              (proves the body was not tampered with)
 *
 * No request reaches business logic until all nine pass. Every failure is
 * recorded as a security event and counted against the device, so a station
 * being probed suspends itself rather than absorbing an unlimited number of
 * guesses.
 *
 * @package App\Services
 * @version 1.0.0
 */
class DeviceAuthenticationService
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly NonceRepository $nonces,
        private readonly SecurityEventService $security,
        private readonly AuthGuard $guard
    ) {
    }

    /**
     * Authenticate a device request.
     *
     * @return array<string,mixed> The device record, on success.
     *
     * @throws DeviceAuthenticationException
     */
    public function authenticate(Request $request): array
    {
        /** @var array<string,string> $headers */
        $headers = (array) config('api.headers', []);

        $deviceCode = $this->credential($request, $headers['device_id'] ?? 'X-Device-Id', 'device_id');
        $apiKey     = $this->credential($request, $headers['api_key'] ?? 'X-Api-Key', 'api_key');
        $timestamp  = $this->credential($request, $headers['timestamp'] ?? 'X-Timestamp', 'timestamp');
        $nonce      = $this->credential($request, $headers['nonce'] ?? 'X-Nonce', 'nonce');
        $signature  = $this->credential($request, $headers['signature'] ?? 'X-Signature', 'signature');
        $firmware   = $this->credential($request, $headers['firmware'] ?? 'X-Firmware-Version', 'firmware_version');

        // 1. Credentials present.
        if ($deviceCode === '' || $apiKey === '' || $timestamp === '' || $nonce === '') {
            throw $this->reject(
                DeviceAuthenticationException::missingCredentials(),
                'A device request arrived without the required identification headers.',
                ['device_code' => $deviceCode]
            );
        }

        // 2. Device registered. An unknown device never creates any record
        //    beyond the security event that documents the attempt.
        $device = $this->devices->findByCode($deviceCode);

        if ($device === null) {
            throw $this->reject(
                DeviceAuthenticationException::unknownDevice($deviceCode),
                sprintf('A request arrived from unregistered device "%s".', $deviceCode),
                ['device_code' => $deviceCode]
            );
        }

        $deviceId = (int) $device['device_id'];

        // 3. Device active.
        $this->assertUsable($device, $deviceCode, $deviceId);

        // 4. Source address, when the device is pinned to one.
        $allowedIp = (string) ($device['allowed_ip'] ?? '');
        if ($allowedIp !== '' && $allowedIp !== $request->ip()) {
            throw $this->reject(
                DeviceAuthenticationException::addressNotAllowed($request->ip()),
                sprintf('Device "%s" called from %s, which is not its registered address.', $deviceCode, $request->ip()),
                ['device_code' => $deviceCode, 'expected' => $allowedIp, 'received' => $request->ip()],
                $deviceId
            );
        }

        // 5. Firmware allow-list, when one is configured.
        /** @var list<string> $allowedFirmware */
        $allowedFirmware = (array) config('api.device.allowed_firmware', []);
        if ($allowedFirmware !== [] && $firmware !== '' && !in_array($firmware, $allowedFirmware, true)) {
            throw $this->reject(
                DeviceAuthenticationException::firmwareNotAllowed($firmware),
                sprintf('Device "%s" reported firmware %s, which is not permitted.', $deviceCode, $firmware),
                ['device_code' => $deviceCode, 'firmware_version' => $firmware],
                $deviceId
            );
        }

        // 6. API key. hash_equals keeps the comparison constant-time, so the
        //    number of correct leading characters cannot be measured.
        if (!Str::secureEquals((string) $device['api_key_hash'], Hasher::hashToken($apiKey))) {
            throw $this->reject(
                DeviceAuthenticationException::invalidApiKey($deviceCode),
                sprintf('Device "%s" presented an incorrect API key.', $deviceCode),
                ['device_code' => $deviceCode, 'key_prefix' => substr($apiKey, 0, 8)],
                $deviceId
            );
        }

        // 7. Timestamp freshness.
        $this->assertFreshTimestamp($timestamp, $deviceCode, $deviceId);

        // 8. Nonce uniqueness.
        $this->assertUnusedNonce($deviceCode, $nonce, $deviceId, $timestamp);

        // 9. Request signature.
        if ((bool) config('api.device.require_signature', true)) {
            $this->assertValidSignature($request, $device, $apiKey, $deviceCode, $timestamp, $nonce, $signature, $deviceId);
        }

        $this->devices->recordCommunication($deviceId, $request->ip(), $firmware === '' ? null : $firmware);
        $this->guard->setDevice($deviceId, $deviceCode);
        $request->setAttribute('device', $device);

        return $device;
    }

    /**
     * Reject a device that is registered but not currently permitted to talk.
     *
     * @param array<string,mixed> $device
     *
     * @throws DeviceAuthenticationException
     */
    private function assertUsable(array $device, string $deviceCode, int $deviceId): void
    {
        $status = (string) $device['status'];

        if ($device['deleted_at'] !== null) {
            throw $this->reject(
                DeviceAuthenticationException::inactiveDevice($deviceCode),
                sprintf('Decommissioned device "%s" attempted to communicate.', $deviceCode),
                ['device_code' => $deviceCode],
                $deviceId
            );
        }

        if ($status === 'suspended') {
            $until = $device['suspended_until'] ?? null;

            // A suspension that has served its time is lifted here rather than
            // waiting for the maintenance task, so a station recovers on its
            // own after a transient problem.
            if ($until !== null && strtotime((string) $until) <= time()) {
                $this->devices->reinstate($deviceId);

                return;
            }

            throw $this->reject(
                DeviceAuthenticationException::suspendedDevice($deviceCode),
                sprintf('Suspended device "%s" attempted to communicate.', $deviceCode),
                ['device_code' => $deviceCode, 'suspended_until' => $until],
                $deviceId
            );
        }

        if ($status !== 'active') {
            throw $this->reject(
                DeviceAuthenticationException::inactiveDevice($deviceCode),
                sprintf('Device "%s" attempted to communicate while %s.', $deviceCode, $status),
                ['device_code' => $deviceCode, 'status' => $status],
                $deviceId
            );
        }
    }

    /**
     * Reject a timestamp outside the permitted clock skew.
     *
     * @throws DeviceAuthenticationException
     */
    private function assertFreshTimestamp(string $timestamp, string $deviceCode, int $deviceId): void
    {
        $tolerance = (int) config('api.device.timestamp_tolerance', 120);
        $parsed    = strtotime($timestamp);

        if ($parsed === false) {
            throw $this->reject(
                DeviceAuthenticationException::staleTimestamp(0),
                sprintf('Device "%s" sent an unparseable timestamp.', $deviceCode),
                ['device_code' => $deviceCode, 'timestamp' => $timestamp],
                $deviceId
            );
        }

        $skew = abs(time() - $parsed);

        if ($skew > $tolerance) {
            throw $this->reject(
                DeviceAuthenticationException::staleTimestamp($skew),
                sprintf('Device "%s" sent a timestamp %d seconds out of step; the tolerance is %d.', $deviceCode, $skew, $tolerance),
                ['device_code' => $deviceCode, 'skew_seconds' => $skew, 'tolerance' => $tolerance],
                $deviceId
            );
        }
    }

    /**
     * Consume the nonce, rejecting a repeat as a replay.
     *
     * @throws DeviceAuthenticationException
     */
    private function assertUnusedNonce(string $deviceCode, string $nonce, int $deviceId, string $timestamp): void
    {
        $ttl    = (int) config('api.device.nonce_ttl', 600);
        $parsed = strtotime($timestamp);

        $fresh = $this->nonces->consume(
            identity: $deviceCode,
            nonce: $nonce,
            deviceId: $deviceId,
            requestTimestamp: date('Y-m-d H:i:s', $parsed === false ? time() : $parsed),
            ttlSeconds: $ttl
        );

        if (!$fresh) {
            throw $this->reject(
                DeviceAuthenticationException::replayDetected($nonce),
                sprintf('A previously used nonce was presented by device "%s"; the request was refused as a replay.', $deviceCode),
                ['device_code' => $deviceCode, 'nonce' => $nonce],
                $deviceId
            );
        }
    }

    /**
     * Verify the HMAC over the canonical request.
     *
     * What this proves, precisely: the body, path, method, timestamp and nonce
     * were not altered after being signed by a party holding the device's API
     * key. An attacker who captures a valid request therefore cannot change the
     * RFID it carries and resend it, even inside the replay window.
     *
     * What it does not prove: possession of a second, independent secret. The
     * signing key is derived from the API key, which the request also carries.
     * The API key is verified against its stored hash before this runs, so the
     * signature adds integrity over the payload rather than a second factor.
     *
     * @param array<string,mixed> $device
     *
     * @throws DeviceAuthenticationException
     */
    private function assertValidSignature(
        Request $request,
        array $device,
        string $apiKey,
        string $deviceCode,
        string $timestamp,
        string $nonce,
        string $signature,
        int $deviceId
    ): void {
        if ($signature === '') {
            throw $this->reject(
                DeviceAuthenticationException::invalidSignature(),
                sprintf('Device "%s" sent an unsigned request while signing is required.', $deviceCode),
                ['device_code' => $deviceCode],
                $deviceId
            );
        }

        $storedHash = (string) ($device['signing_secret_hash'] ?? '');

        if ($storedHash === '') {
            throw $this->reject(
                DeviceAuthenticationException::invalidSignature(),
                sprintf(
                    'Device "%s" has no signing secret provisioned; rotate its API key to issue one.',
                    $deviceCode
                ),
                ['device_code' => $deviceCode, 'reason' => 'signing_not_provisioned'],
                $deviceId
            );
        }

        $secret = $this->signingSecret($apiKey);

        // A key rotation that updated the API key but not the signing hash
        // would leave the two out of step. Catching it here turns a confusing
        // "every request is unsigned" outage into a precise diagnostic.
        if (!Str::secureEquals($storedHash, Hasher::hashToken($secret))) {
            throw $this->reject(
                DeviceAuthenticationException::invalidSignature(),
                sprintf(
                    'The signing secret recorded for device "%s" does not match its API key; the credentials are inconsistent and must be reissued.',
                    $deviceCode
                ),
                ['device_code' => $deviceCode, 'reason' => 'signing_secret_mismatch'],
                $deviceId
            );
        }

        $expected = $this->signature(
            secret: $secret,
            method: $request->method(),
            path: $request->path(),
            timestamp: $timestamp,
            nonce: $nonce,
            body: $request->rawBody()
        );

        if (!Str::secureEquals($expected, strtolower(trim($signature)))) {
            throw $this->reject(
                DeviceAuthenticationException::invalidSignature(),
                sprintf('Device "%s" sent a request whose signature does not match its content.', $deviceCode),
                ['device_code' => $deviceCode],
                $deviceId
            );
        }
    }

    /**
     * Compute the canonical request signature.
     *
     * The canonical form is a newline-joined tuple, so a value cannot be moved
     * between fields without changing the digest.
     */
    public function signature(
        string $secret,
        string $method,
        string $path,
        string $timestamp,
        string $nonce,
        string $body
    ): string {
        $canonical = implode("\n", [
            strtoupper($method),
            '/' . trim($path, '/'),
            $timestamp,
            $nonce,
            hash('sha256', $body),
        ]);

        return hash_hmac(
            (string) config('api.device.signature_algorithm', 'sha256'),
            $canonical,
            $secret
        );
    }

    /**
     * Derive the secret a device signs with from its API key.
     *
     * The suffix domain-separates the signing key from the key itself, so the
     * value used for HMAC is never the same string that is compared against
     * the stored credential hash.
     */
    public function signingSecret(string $apiKey): string
    {
        return $apiKey . '-signing';
    }

    /**
     * Read a credential from its header, falling back to the JSON body.
     *
     * Constrained ESP32 HTTP clients sometimes find headers awkward, so the
     * body is accepted as an alternative carrier for the same values.
     */
    private function credential(Request $request, string $header, string $bodyKey): string
    {
        $value = $request->header($header);

        if ($value !== null && $value !== '') {
            return trim($value);
        }

        $fromBody = $request->input($bodyKey);

        return is_scalar($fromBody) ? trim((string) $fromBody) : '';
    }

    /**
     * Record a rejection and hand back the exception for the caller to throw.
     *
     * @param array<string,mixed> $detail
     */
    private function reject(
        DeviceAuthenticationException $exception,
        string $description,
        array $detail,
        ?int $deviceId = null
    ): DeviceAuthenticationException {
        $this->security->record(
            $exception->eventType(),
            $description,
            $detail,
            'rejected',
            $exception->severity() === 'critical' ? 'critical' : 'high'
        );

        // A registered device being probed accumulates failures and suspends
        // itself, which bounds how long an attacker can keep guessing.
        if ($deviceId !== null) {
            $failures = $this->devices->recordAuthenticationFailure($deviceId);
            $maximum  = (int) config('api.device.max_auth_failures', 10);

            if ($maximum > 0 && $failures >= $maximum) {
                $minutes = (int) config('api.device.suspend_minutes', 30);

                $this->devices->suspend(
                    $deviceId,
                    now()->modify('+' . max(1, $minutes) . ' minutes')->format('Y-m-d H:i:s'),
                    sprintf('Suspended automatically after %d authentication failures.', $failures)
                );

                $this->security->record(
                    'suspended_device',
                    sprintf('Device %d was suspended for %d minutes after %d authentication failures.', $deviceId, $minutes, $failures),
                    ['device_id' => $deviceId, 'failures' => $failures],
                    'device_suspended',
                    'critical'
                );
            }
        }

        return $exception;
    }

    /**
     * Issue a new API key for a device.
     *
     * @return array{key:string,prefix:string,hash:string,signing_hash:string}
     */
    public function issueApiKey(): array
    {
        $bytes = max(16, (int) config('api.device.api_key_bytes', 32));
        $key   = Str::randomToken($bytes);

        return [
            'key'          => $key,
            'prefix'       => substr($key, 0, 12),
            'hash'         => Hasher::hashToken($key),
            'signing_hash' => Hasher::hashToken($key . '-signing'),
        ];
    }
}
