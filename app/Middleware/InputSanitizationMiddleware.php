<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Support\Str;
use App\Exceptions\InvalidRequestException;
use Closure;

/**
 * Normalises every inbound value before anything downstream reads it.
 *
 * This is normalisation, not escaping. Output encoding still happens at the
 * point of rendering, and SQL safety still comes from prepared statements —
 * stripping "dangerous characters" here would be a false comfort and would
 * corrupt legitimate data such as a surname containing an apostrophe.
 *
 * What it does do is guarantee that downstream code never sees invalid UTF-8,
 * embedded null bytes, control characters, or an absurdly nested structure.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class InputSanitizationMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var array<string,mixed> $rules */
        $rules = (array) config('security.sanitisation', []);

        // Query and body are cleaned separately so they stay distinguishable
        // downstream: a controller that deliberately reads only the query
        // string must not start seeing body values.
        $request->replaceInput(
            $this->clean($request->queryAll(), $rules, 0),
            $this->clean($request->bodyAll(), $rules, 0)
        );

        return $next($request);
    }

    /**
     * Recursively normalise an input array.
     *
     * @param array<string,mixed> $input
     * @param array<string,mixed> $rules
     *
     * @return array<string,mixed>
     *
     * @throws InvalidRequestException
     */
    private function clean(array $input, array $rules, int $depth): array
    {
        $maxDepth = (int) ($rules['max_input_depth'] ?? 12);

        if ($depth > $maxDepth) {
            // Deeply nested input is never legitimate here and is a classic way
            // to exhaust memory during decoding.
            throw new InvalidRequestException('The request structure is nested more deeply than permitted.');
        }

        $result = [];

        foreach ($input as $key => $value) {
            $cleanKey = $this->cleanKey((string) $key);

            if ($cleanKey === '') {
                continue;
            }

            $result[$cleanKey] = is_array($value)
                ? $this->clean($value, $rules, $depth + 1)
                : $this->cleanValue($value, $rules);
        }

        return $result;
    }

    /**
     * Normalise a scalar value.
     *
     * @param array<string,mixed> $rules
     *
     * @throws InvalidRequestException
     */
    private function cleanValue(mixed $value, array $rules): mixed
    {
        if (!is_string($value)) {
            return $value;
        }

        // A null byte truncates a string in several C-level functions, which
        // has historically been used to slip past extension checks.
        $value = str_replace("\0", '', $value);

        if (($rules['reject_invalid_utf8'] ?? true) === true && !mb_check_encoding($value, 'UTF-8')) {
            throw new InvalidRequestException('The request contains text that is not valid UTF-8.');
        }

        if (($rules['strip_control_chars'] ?? true) === true) {
            $value = Str::stripControlCharacters($value);
        }

        if (($rules['trim_strings'] ?? true) === true) {
            $value = trim($value);
        }

        $maxLength = (int) ($rules['max_field_length'] ?? 65535);
        if ($maxLength > 0 && mb_strlen($value) > $maxLength) {
            throw new InvalidRequestException('A submitted field exceeds the maximum permitted length.');
        }

        // An empty string from an untouched form field is more usefully a null
        // than an empty string, so optional columns stay NULL rather than ''.
        if (($rules['convert_empty_to_null'] ?? true) === true && $value === '') {
            return null;
        }

        return $value;
    }

    /**
     * Normalise a field name, discarding anything that is not a plausible key.
     */
    private function cleanKey(string $key): string
    {
        $key = str_replace("\0", '', $key);

        if (!mb_check_encoding($key, 'UTF-8')) {
            return '';
        }

        $key = trim(Str::stripControlCharacters($key));

        return mb_strlen($key) > 100 ? '' : $key;
    }
}
