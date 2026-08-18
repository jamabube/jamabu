<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * JSON response.
 *
 * Every API response in the system is produced through this class (usually via
 * ApiResponse) so the envelope stays identical across every endpoint.
 *
 * @package App\Core\Http
 * @version 1.0.0
 */
class JsonResponse extends Response
{
    private const ENCODE_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

    /**
     * @param array<string,mixed>  $payload
     * @param array<string,string> $headers
     */
    public function __construct(array $payload, int $status = 200, array $headers = [])
    {
        $encoded = json_encode($payload, self::ENCODE_FLAGS);

        parent::__construct(
            $encoded === false ? '{"success":false,"error_code":"ENCODING_ERROR","message":"The response could not be encoded."}' : $encoded,
            $status,
            $headers + [
                'Content-Type'           => 'application/json; charset=UTF-8',
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control'          => 'no-store, no-cache, must-revalidate',
            ]
        );
    }

    /**
     * Decode the payload back into an array. Used by the test harness.
     *
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        /** @var mixed $decoded */
        $decoded = json_decode($this->content(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
