<?php

declare(strict_types=1);

namespace App\Responses;

use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Core\Support\Str;

/**
 * Factory for the system-wide REST envelope.
 *
 * Every endpoint answers with the same shape, so a client (browser, ESP32 or a
 * future mobile application) can parse any response with one code path:
 *
 *   success: { success, message, data, meta?, timestamp, request_id }
 *   failure: { success, error_code, message, details, timestamp, request_id }
 *
 * @package App\Responses
 * @version 1.0.0
 */
final class ApiResponse
{
    /**
     * The request currently being served. Set once by the HTTP kernel so every
     * response can carry the correlation id without threading it through every
     * service call.
     */
    private static ?Request $request = null;

    public static function bindRequest(?Request $request): void
    {
        self::$request = $request;
    }

    /**
     * Successful response.
     *
     * @param array<string,mixed>|list<mixed> $data
     * @param array<string,mixed>             $meta
     * @param array<string,string>            $headers
     */
    public static function success(
        string $message = 'Request completed successfully.',
        array $data = [],
        int $status = 200,
        array $meta = [],
        array $headers = []
    ): JsonResponse {
        $payload = [
            'success' => true,
            'message' => $message,
            'data'    => $data,
        ];

        // meta carries pagination and other envelope-level information.
        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        $payload['timestamp']  = self::timestamp();
        $payload['request_id'] = self::requestId();

        return new JsonResponse($payload, $status, $headers);
    }

    /**
     * Resource-created response.
     *
     * @param array<string,mixed> $data
     */
    public static function created(string $message, array $data = [], ?string $location = null): JsonResponse
    {
        $headers = $location === null ? [] : ['Location' => $location];

        return self::success($message, $data, 201, [], $headers);
    }

    /**
     * Successful response with no body content.
     */
    public static function deleted(string $message = 'The record was removed successfully.'): JsonResponse
    {
        return self::success($message, [], 200);
    }

    /**
     * Failure response.
     *
     * @param array<string,mixed>|list<mixed> $details
     * @param array<string,string>            $headers
     */
    public static function error(
        string $errorCode,
        string $message,
        int $status = 400,
        array $details = [],
        array $headers = []
    ): JsonResponse {
        return new JsonResponse([
            'success'    => false,
            'error_code' => $errorCode,
            'message'    => $message,
            'details'    => $details,
            'timestamp'  => self::timestamp(),
            'request_id' => self::requestId(),
        ], $status, $headers);
    }

    /**
     * Validation failure (HTTP 422) with a field => messages map.
     *
     * @param array<string,list<string>> $errors
     */
    public static function validationFailed(array $errors, string $message = 'The submitted data is invalid.'): JsonResponse
    {
        return self::error('VALIDATION_FAILED', $message, 422, ['errors' => $errors]);
    }

    public static function unauthenticated(string $message = 'Authentication is required.'): JsonResponse
    {
        return self::error('UNAUTHENTICATED', $message, 401);
    }

    public static function forbidden(string $message = 'You do not have permission to perform this action.'): JsonResponse
    {
        return self::error('FORBIDDEN', $message, 403);
    }

    public static function notFound(string $message = 'The requested resource could not be found.'): JsonResponse
    {
        return self::error('NOT_FOUND', $message, 404);
    }

    public static function conflict(string $errorCode, string $message): JsonResponse
    {
        return self::error($errorCode, $message, 409);
    }

    public static function rateLimited(int $retryAfter, string $message = 'Too many requests.'): JsonResponse
    {
        return self::error('RATE_LIMIT_EXCEEDED', $message, 429, ['retry_after' => $retryAfter], [
            'Retry-After' => (string) $retryAfter,
        ]);
    }

    public static function serverError(string $message = 'An internal error prevented this request from completing.'): JsonResponse
    {
        return self::error('INTERNAL_ERROR', $message, 500);
    }

    /**
     * Paginated collection response.
     *
     * @param list<array<string,mixed>> $items
     * @param array<string,mixed>       $pagination Output of Paginator::toArray().
     */
    public static function paginated(string $message, array $items, array $pagination): JsonResponse
    {
        return self::success($message, $items, 200, ['pagination' => $pagination]);
    }

    /**
     * ISO-8601 timestamp in the application timezone.
     */
    private static function timestamp(): string
    {
        return now()->format(DATE_ATOM);
    }

    private static function requestId(): string
    {
        return self::$request?->requestId() ?? Str::uuid();
    }
}
