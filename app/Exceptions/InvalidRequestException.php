<?php

declare(strict_types=1);

namespace App\Exceptions;

/**
 * Raised for malformed requests: bad JSON, wrong content type, oversized body,
 * unsupported HTTP method, or unexpected parameters.
 */
class InvalidRequestException extends VamsException
{
    protected string $errorCode = 'BAD_REQUEST';
    protected int $statusCode = 400;
    protected string $severity = 'notice';

    protected function defaultMessage(): string
    {
        return 'The request could not be understood.';
    }

    public static function malformedJson(string $reason): self
    {
        $exception = new self('The request body is not valid JSON.');
        $exception->errorCode = 'MALFORMED_JSON';
        $exception->context   = ['reason' => $reason];

        return $exception;
    }

    public static function unsupportedMediaType(string $received): self
    {
        $exception = new self('The request Content-Type is not supported.');
        $exception->errorCode  = 'UNSUPPORTED_MEDIA_TYPE';
        $exception->statusCode = 415;
        $exception->context    = ['content_type' => $received];

        return $exception;
    }

    public static function payloadTooLarge(int $bytes, int $limit): self
    {
        $exception = new self('The request body exceeds the permitted size.');
        $exception->errorCode  = 'PAYLOAD_TOO_LARGE';
        $exception->statusCode = 413;
        $exception->context    = ['bytes' => $bytes, 'limit' => $limit];

        return $exception;
    }

    public static function unexpectedParameters(array $keys): self
    {
        $exception = new self('The request contains unexpected parameters.', ['unexpected' => array_values($keys)]);
        $exception->errorCode = 'UNEXPECTED_PARAMETERS';

        return $exception;
    }
}
