<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * Array utilities.
 *
 * @package App\Core\Support
 * @version 1.0.0
 */
final class Arr
{
    /**
     * Read a nested value using dot notation.
     *
     * @param array<array-key,mixed> $array
     */
    public static function get(array $array, string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        $value = $array;
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Write a nested value using dot notation.
     *
     * @param array<array-key,mixed> $array
     */
    public static function set(array &$array, string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor   = &$array;

        while (count($segments) > 1) {
            $segment = array_shift($segments);
            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }
            $cursor = &$cursor[$segment];
        }

        $cursor[array_shift($segments)] = $value;
    }

    public static function has(array $array, string $key): bool
    {
        return self::get($array, $key, '__vams_missing__') !== '__vams_missing__';
    }

    /**
     * Return only the listed keys.
     *
     * @param array<array-key,mixed> $array
     * @param list<string>           $keys
     *
     * @return array<array-key,mixed>
     */
    public static function only(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * Return everything except the listed keys.
     *
     * @param array<array-key,mixed> $array
     * @param list<string>           $keys
     *
     * @return array<array-key,mixed>
     */
    public static function except(array $array, array $keys): array
    {
        return array_diff_key($array, array_flip($keys));
    }

    /**
     * Extract a single column, optionally keyed by another column.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return array<array-key,mixed>
     */
    public static function pluck(array $rows, string $column, ?string $key = null): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!array_key_exists($column, $row)) {
                continue;
            }

            if ($key !== null && array_key_exists($key, $row)) {
                /** @var array-key $index */
                $index          = $row[$key];
                $result[$index] = $row[$column];
                continue;
            }

            $result[] = $row[$column];
        }

        return $result;
    }

    /**
     * Group rows by the value of a column.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return array<array-key,list<array<string,mixed>>>
     */
    public static function groupBy(array $rows, string $column): array
    {
        $groups = [];

        foreach ($rows as $row) {
            /** @var array-key $index */
            $index            = $row[$column] ?? '';
            $groups[$index][] = $row;
        }

        return $groups;
    }

    /**
     * Index rows by a column, keeping the last occurrence.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return array<array-key,array<string,mixed>>
     */
    public static function keyBy(array $rows, string $column): array
    {
        $result = [];

        foreach ($rows as $row) {
            if (!array_key_exists($column, $row)) {
                continue;
            }
            /** @var array-key $index */
            $index          = $row[$column];
            $result[$index] = $row;
        }

        return $result;
    }

    /**
     * Recursively remove the values of sensitive keys before logging.
     *
     * @param array<array-key,mixed> $data
     * @param list<string>           $sensitiveKeys
     *
     * @return array<array-key,mixed>
     */
    public static function redact(array $data, array $sensitiveKeys, string $replacement = '[redacted]'): array
    {
        $lookup = array_map('strtolower', $sensitiveKeys);
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $lookup, true)) {
                $result[$key] = $replacement;
                continue;
            }

            $result[$key] = is_array($value)
                ? self::redact($value, $sensitiveKeys, $replacement)
                : $value;
        }

        return $result;
    }

    /**
     * Flatten a nested array into dot-notation keys.
     *
     * @param array<array-key,mixed> $array
     *
     * @return array<string,mixed>
     */
    public static function dot(array $array, string $prefix = ''): array
    {
        $result = [];

        foreach ($array as $key => $value) {
            $compound = $prefix === '' ? (string) $key : $prefix . '.' . $key;

            if (is_array($value) && $value !== []) {
                $result += self::dot($value, $compound);
                continue;
            }

            $result[$compound] = $value;
        }

        return $result;
    }

    /**
     * Compute the differences between two associative arrays.
     *
     * Returns [old, new] maps containing only the keys whose value changed.
     * This is what the audit trail stores for an update operation.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     *
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    public static function diff(array $before, array $after): array
    {
        $oldValues = [];
        $newValues = [];

        foreach ($after as $key => $value) {
            $previous = $before[$key] ?? null;

            // Loose scalar comparison avoids logging "1" -> 1 as a change.
            if (is_scalar($value) && is_scalar($previous) && (string) $previous === (string) $value) {
                continue;
            }

            if ($previous === $value) {
                continue;
            }

            $oldValues[$key] = $previous;
            $newValues[$key] = $value;
        }

        return [$oldValues, $newValues];
    }
}
