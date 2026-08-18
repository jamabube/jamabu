<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Core\Http\Request;
use App\Services\DeviceAuthenticationService;

/**
 * Builds Request objects for tests without going through the web server.
 *
 * @package Tests\Support
 * @version 1.0.0
 */
final class RequestFactory
{
    /**
     * A plain request from a browser-like client.
     *
     * @param array<string,mixed>  $body
     * @param array<string,string> $headers
     */
    public static function make(
        string $method,
        string $path,
        array $body = [],
        array $headers = [],
        string $rawBody = '',
        string $ipAddress = '192.168.10.50'
    ): Request {
        $server = [
            'REMOTE_ADDR'        => $ipAddress,
            'REQUEST_TIME_FLOAT' => microtime(true),
            'HTTP_USER_AGENT'    => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36',
        ];

        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return new Request($method, $path, [], $body, $server, [], [], $rawBody);
    }

    /**
     * A correctly signed device request.
     *
     * @param array<string,mixed> $body
     */
    public static function signedDevice(
        DeviceAuthenticationService $service,
        string $deviceCode,
        string $apiKey,
        array $body,
        string $path = '/api/v1/access/entry',
        ?string $nonce = null,
        ?string $timestamp = null,
        ?string $signatureOverride = null,
        ?string $rawBodyOverride = null
    ): Request {
        $nonce     ??= bin2hex(random_bytes(16));
        $timestamp ??= date('c');

        $signedBody = (string) json_encode($body);
        $sentBody   = $rawBodyOverride ?? $signedBody;

        // The signature is always computed over the body it claims to cover;
        // passing a different raw body simulates tampering in transit.
        $signature = $signatureOverride ?? $service->signature(
            $service->signingSecret($apiKey),
            'POST',
            $path,
            $timestamp,
            $nonce,
            $signedBody
        );

        return self::make('POST', $path, $body, [
            'X-Device-Id'        => $deviceCode,
            'X-Api-Key'          => $apiKey,
            'X-Timestamp'        => $timestamp,
            'X-Nonce'            => $nonce,
            'X-Signature'        => $signature,
            'X-Firmware-Version' => '1.0.0',
            'Content-Type'       => 'application/json',
        ], $sentBody);
    }
}
