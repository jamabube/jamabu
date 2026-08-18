<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Security\Hasher;
use App\Core\Support\Str;
use App\Exceptions\DeviceAuthenticationException;
use App\Repositories\DeviceRepository;
use App\Services\DeviceAuthenticationService;
use Tests\Support\RequestFactory;
use Tests\TestCase;

/**
 * Exercises the nine-stage device authentication pipeline.
 *
 * Each test drives one stage to failure and asserts the specific error code,
 * so a change that accidentally short-circuits a check is caught rather than
 * being masked by an earlier one.
 *
 * @package Tests\Integration
 * @version 1.0.0
 */
final class DeviceAuthenticationTest extends TestCase
{
    protected bool $requiresDatabase = true;

    private const DEVICE_CODE = 'ESP32-TEST-99';

    private DeviceAuthenticationService $deviceAuth;
    private DeviceRepository $devices;
    private string $apiKey = '';
    private int $deviceId = 0;

    /** @var array<string,mixed> */
    private array $body = ['rfid_uid' => 'A0000000', 'action' => 'entry'];

    public function description(): string
    {
        return 'ESP32 credential, replay and signature verification';
    }

    public function setUp(): void
    {
        $this->deviceAuth = $this->app->make(DeviceAuthenticationService::class);
        $this->devices    = $this->app->make(DeviceRepository::class);

        $credentials  = $this->deviceAuth->issueApiKey();
        $this->apiKey = $credentials['key'];

        $existing = $this->devices->findByCode(self::DEVICE_CODE);

        if ($existing === null) {
            $this->deviceId = $this->devices->create([
                'device_code'         => self::DEVICE_CODE,
                'device_name'         => 'Automated Test Station',
                'api_key_hash'        => $credentials['hash'],
                'api_key_prefix'      => $credentials['prefix'],
                'signing_secret_hash' => $credentials['signing_hash'],
                'gate_type'           => 'both',
                'firmware_version'    => '1.0.0',
                'heartbeat_interval'  => 30,
                'status'              => 'active',
            ]);
        } else {
            $this->deviceId = (int) $existing['device_id'];
            $this->devices->rotateApiKey(
                $this->deviceId,
                $credentials['hash'],
                $credentials['prefix'],
                $credentials['signing_hash'],
                null
            );
            $this->devices->reinstate($this->deviceId);
        }
    }

    public function tearDown(): void
    {
        if ($this->deviceId > 0) {
            $this->devices->reinstate($this->deviceId);
        }
    }

    public function testCorrectlySignedRequestIsAccepted(): void
    {
        $request = RequestFactory::signedDevice($this->deviceAuth, self::DEVICE_CODE, $this->apiKey, $this->body);

        $this->assertDoesNotThrow(
            fn () => $this->deviceAuth->authenticate($request),
            'a correctly signed request from a registered device is accepted'
        );
    }

    public function testUnknownDeviceIsRejected(): void
    {
        $this->assertThrows(
            fn () => $this->deviceAuth->authenticate(
                RequestFactory::signedDevice($this->deviceAuth, 'ESP32-NOT-REGISTERED', $this->apiKey, $this->body)
            ),
            'an unregistered device is refused',
            DeviceAuthenticationException::class,
            'DEVICE_UNKNOWN'
        );
    }

    public function testMissingCredentialsAreRejected(): void
    {
        $this->assertThrows(
            fn () => $this->deviceAuth->authenticate(
                RequestFactory::make('POST', '/api/v1/access/entry', $this->body, ['Content-Type' => 'application/json'])
            ),
            'a request without identification headers is refused',
            DeviceAuthenticationException::class,
            'DEVICE_CREDENTIALS_MISSING'
        );
    }

    public function testWrongApiKeyIsRejected(): void
    {
        $this->assertThrows(
            fn () => $this->deviceAuth->authenticate(
                RequestFactory::signedDevice($this->deviceAuth, self::DEVICE_CODE, Str::randomToken(32), $this->body)
            ),
            'an incorrect API key is refused',
            DeviceAuthenticationException::class,
            'DEVICE_INVALID_API_KEY'
        );
    }

    public function testStaleTimestampIsRejected(): void
    {
        $this->assertThrows(
            fn () => $this->deviceAuth->authenticate(
                RequestFactory::signedDevice(
                    $this->deviceAuth,
                    self::DEVICE_CODE,
                    $this->apiKey,
                    $this->body,
                    timestamp: date('c', time() - 7200)
                )
            ),
            'a timestamp outside the tolerance is refused',
            DeviceAuthenticationException::class,
            'DEVICE_TIMESTAMP_INVALID'
        );
    }

    public function testReplayedNonceIsRejected(): void
    {
        $nonce     = bin2hex(random_bytes(16));
        $timestamp = date('c');

        $first = RequestFactory::signedDevice(
            $this->deviceAuth, self::DEVICE_CODE, $this->apiKey, $this->body,
            nonce: $nonce, timestamp: $timestamp
        );

        $this->assertDoesNotThrow(
            fn () => $this->deviceAuth->authenticate($first),
            'the first use of a nonce is accepted'
        );

        // The identical request, replayed. Everything about it is still valid
        // except that it has been seen before.
        $replay = RequestFactory::signedDevice(
            $this->deviceAuth, self::DEVICE_CODE, $this->apiKey, $this->body,
            nonce: $nonce, timestamp: $timestamp
        );

        $this->assertThrows(
            fn () => $this->deviceAuth->authenticate($replay),
            'a captured request replayed verbatim is refused',
            DeviceAuthenticationException::class,
            'DEVICE_REPLAY_DETECTED'
        );
    }

    public function testTamperedSignatureIsRejected(): void
    {
        $this->assertThrows(
            fn () => $this->deviceAuth->authenticate(
                RequestFactory::signedDevice(
                    $this->deviceAuth, self::DEVICE_CODE, $this->apiKey, $this->body,
                    signatureOverride: str_repeat('a', 64)
                )
            ),
            'a request with an incorrect signature is refused',
            DeviceAuthenticationException::class,
            'DEVICE_SIGNATURE_INVALID'
        );
    }

    public function testAlteredBodyWithCapturedSignatureIsRejected(): void
    {
        // The scenario that matters: an attacker captures a valid scan and
        // changes the RFID it carries, keeping the original signature.
        $request = RequestFactory::signedDevice(
            $this->deviceAuth,
            self::DEVICE_CODE,
            $this->apiKey,
            $this->body,
            rawBodyOverride: (string) json_encode(['rfid_uid' => 'DEADBEEF', 'action' => 'entry'])
        );

        $this->assertThrows(
            fn () => $this->deviceAuth->authenticate($request),
            'an altered body under a captured signature is refused',
            DeviceAuthenticationException::class,
            'DEVICE_SIGNATURE_INVALID'
        );
    }

    public function testSuspendedDeviceIsRejected(): void
    {
        $this->devices->suspend(
            $this->deviceId,
            now()->modify('+10 minutes')->format('Y-m-d H:i:s'),
            'Suspended by the automated test suite.'
        );

        $this->assertThrows(
            fn () => $this->deviceAuth->authenticate(
                RequestFactory::signedDevice($this->deviceAuth, self::DEVICE_CODE, $this->apiKey, $this->body)
            ),
            'a suspended device cannot communicate',
            DeviceAuthenticationException::class,
            'DEVICE_SUSPENDED'
        );

        $this->devices->reinstate($this->deviceId);
    }

    public function testInactiveDeviceIsRejected(): void
    {
        $this->devices->update($this->deviceId, ['status' => 'maintenance']);

        $this->assertThrows(
            fn () => $this->deviceAuth->authenticate(
                RequestFactory::signedDevice($this->deviceAuth, self::DEVICE_CODE, $this->apiKey, $this->body)
            ),
            'a device under maintenance cannot communicate',
            DeviceAuthenticationException::class,
            'DEVICE_INACTIVE'
        );

        $this->devices->update($this->deviceId, ['status' => 'active']);
    }

    public function testAddressPinningIsEnforced(): void
    {
        $this->devices->update($this->deviceId, ['allowed_ip' => '10.99.99.99']);

        $this->assertThrows(
            fn () => $this->deviceAuth->authenticate(
                RequestFactory::signedDevice($this->deviceAuth, self::DEVICE_CODE, $this->apiKey, $this->body)
            ),
            'a device pinned to another address is refused',
            DeviceAuthenticationException::class,
            'DEVICE_IP_REJECTED'
        );

        $this->devices->update($this->deviceId, ['allowed_ip' => null]);
        $this->devices->reinstate($this->deviceId);
    }

    public function testApiKeysAreStoredAsHashes(): void
    {
        $record = $this->devices->findByCode(self::DEVICE_CODE);

        $this->assertNotNull($record, 'the test device exists');
        $this->assertNotSame($this->apiKey, (string) $record['api_key_hash'], 'the plain key is not stored');
        $this->assertSame(
            Hasher::hashToken($this->apiKey),
            (string) $record['api_key_hash'],
            'the stored value is the hash of the key'
        );
        $this->assertMatches('/^[0-9a-f]{64}$/', (string) $record['api_key_hash'], 'the stored value is a SHA-256 digest');
    }

    public function testSignatureCoversEveryPartOfTheRequest(): void
    {
        $secret    = $this->deviceAuth->signingSecret($this->apiKey);
        $timestamp = date('c');
        $nonce     = bin2hex(random_bytes(8));
        $body      = '{"rfid_uid":"A0000000"}';

        $baseline = $this->deviceAuth->signature($secret, 'POST', '/api/v1/access/entry', $timestamp, $nonce, $body);

        $this->assertNotSame(
            $baseline,
            $this->deviceAuth->signature($secret, 'POST', '/api/v1/access/exit', $timestamp, $nonce, $body),
            'changing the path changes the signature'
        );

        $this->assertNotSame(
            $baseline,
            $this->deviceAuth->signature($secret, 'PUT', '/api/v1/access/entry', $timestamp, $nonce, $body),
            'changing the method changes the signature'
        );

        $this->assertNotSame(
            $baseline,
            $this->deviceAuth->signature($secret, 'POST', '/api/v1/access/entry', $timestamp, $nonce, '{"rfid_uid":"B0000000"}'),
            'changing the body changes the signature'
        );

        $this->assertNotSame(
            $baseline,
            $this->deviceAuth->signature($secret, 'POST', '/api/v1/access/entry', $timestamp, 'other-nonce', $body),
            'changing the nonce changes the signature'
        );
    }
}
