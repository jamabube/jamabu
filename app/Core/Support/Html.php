<?php

declare(strict_types=1);

namespace App\Core\Support;

use BackedEnum;
use DateTimeInterface;
use Stringable;

/**
 * Output-encoding helpers.
 *
 * Every value rendered into a template passes through this class. Centralising
 * escaping is what makes "escape all output" auditable rather than aspirational.
 *
 * @package App\Core\Support
 * @version 1.0.0
 */
final class Html
{
    /**
     * Escape a value for HTML text content and quoted attribute values.
     */
    public static function escape(mixed $value): string
    {
        return htmlspecialchars(self::stringify($value), ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML5, 'UTF-8');
    }

    /**
     * Escape a value for embedding inside a JavaScript context.
     *
     * Produces a JSON literal with HTML-significant characters escaped so the
     * result cannot terminate the surrounding <script> element.
     */
    public static function js(mixed $value): string
    {
        $json = json_encode(
            $value,
            JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        );

        return $json === false ? 'null' : $json;
    }

    /**
     * Escape a value for use inside a URL query segment.
     */
    public static function url(mixed $value): string
    {
        return rawurlencode(self::stringify($value));
    }

    /**
     * Build an HTML attribute string from a map, skipping null/false values.
     *
     * @param array<string,scalar|null> $attributes
     */
    public static function attributes(array $attributes): string
    {
        $parts = [];

        foreach ($attributes as $name => $value) {
            if ($value === null || $value === false) {
                continue;
            }

            if ($value === true) {
                $parts[] = self::escape($name);
                continue;
            }

            $parts[] = self::escape($name) . '="' . self::escape($value) . '"';
        }

        return implode(' ', $parts);
    }

    /**
     * Convert an arbitrary value into a string suitable for display.
     */
    private static function stringify(mixed $value): string
    {
        return match (true) {
            $value === null            => '',
            is_bool($value)            => $value ? 'Yes' : 'No',
            is_string($value)          => $value,
            is_int($value), is_float($value) => (string) $value,
            $value instanceof BackedEnum      => (string) $value->value,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof Stringable      => (string) $value,
            is_array($value)           => (string) json_encode($value, JSON_UNESCAPED_UNICODE),
            is_object($value) && method_exists($value, '__toString') => (string) $value,
            default                    => '',
        };
    }
}
