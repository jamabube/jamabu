<?php

declare(strict_types=1);

namespace App\Core\Support;

/**
 * String utilities shared across the application.
 *
 * @package App\Core\Support
 * @version 1.0.0
 */
final class Str
{
    /**
     * Convert a string to StudlyCase.
     */
    public static function studly(string $value): string
    {
        return str_replace(' ', '', ucwords(str_replace(['-', '_', '.'], ' ', $value)));
    }

    /**
     * Convert a string to camelCase.
     */
    public static function camel(string $value): string
    {
        return lcfirst(self::studly($value));
    }

    /**
     * Convert a string to snake_case.
     */
    public static function snake(string $value, string $delimiter = '_'): string
    {
        if (ctype_lower($value)) {
            return $value;
        }

        $value = (string) preg_replace('/\s+/u', '', ucwords($value));
        $value = (string) preg_replace('/(.)(?=[A-Z])/u', '$1' . $delimiter, $value);

        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Convert a string to kebab-case.
     */
    public static function kebab(string $value): string
    {
        return self::snake($value, '-');
    }

    /**
     * Convert an identifier into a human-readable title.
     */
    public static function title(string $value): string
    {
        return ucwords(trim(str_replace(['-', '_', '.'], ' ', $value)));
    }

    /**
     * Build a URL/file safe slug.
     */
    public static function slug(string $value, string $separator = '-'): string
    {
        $value = (string) preg_replace('/[^\p{L}\p{Nd}]+/u', $separator, $value);
        $value = trim($value, $separator);

        return mb_strtolower($value, 'UTF-8');
    }

    /**
     * Shorten a string, appending an ellipsis when it was truncated.
     */
    public static function limit(string $value, int $limit = 100, string $end = '...'): string
    {
        if (mb_strwidth($value, 'UTF-8') <= $limit) {
            return $value;
        }

        return rtrim(mb_strimwidth($value, 0, $limit, '', 'UTF-8')) . $end;
    }

    /**
     * Mask all but the last $visible characters. Used for API keys in the UI.
     */
    public static function mask(string $value, int $visible = 4, string $mask = '*'): string
    {
        $length = mb_strlen($value, 'UTF-8');

        if ($length <= $visible) {
            return str_repeat($mask, max($length, 4));
        }

        return str_repeat($mask, min($length - $visible, 24))
            . mb_substr($value, $length - $visible, null, 'UTF-8');
    }

    /**
     * Cryptographically secure random hexadecimal string.
     */
    public static function randomHex(int $bytes = 16): string
    {
        return bin2hex(random_bytes(max(1, $bytes)));
    }

    /**
     * Cryptographically secure URL-safe random token.
     */
    public static function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes(max(1, $bytes))), '+/', '-_'), '=');
    }

    /**
     * Random string drawn from an unambiguous alphabet (no O/0, I/l/1).
     *
     * Used for human-transcribed values such as temporary passwords and
     * visitor card codes.
     */
    public static function randomCode(int $length = 8): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max      = strlen($alphabet) - 1;
        $result   = '';

        for ($i = 0; $i < $length; $i++) {
            $result .= $alphabet[random_int(0, $max)];
        }

        return $result;
    }

    /**
     * Generate an RFC 4122 version 4 UUID.
     */
    public static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Normalise an RFID UID: uppercase hexadecimal with separators removed.
     */
    public static function normaliseUid(string $uid): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $uid));
    }

    /**
     * Normalise a MAC address to uppercase colon-separated form.
     */
    public static function normaliseMac(string $mac): string
    {
        $clean = strtoupper((string) preg_replace('/[^0-9A-Fa-f]/', '', $mac));

        if (strlen($clean) !== 12) {
            return strtoupper(trim($mac));
        }

        return implode(':', str_split($clean, 2));
    }

    /**
     * Normalise a vehicle plate number for storage and comparison.
     */
    public static function normalisePlate(string $plate): string
    {
        return strtoupper(trim((string) preg_replace('/\s+/', ' ', $plate)));
    }

    /**
     * Constant-time string comparison.
     */
    public static function secureEquals(string $known, string $given): bool
    {
        return hash_equals($known, $given);
    }

    /**
     * Remove ASCII control characters (except tab, newline, carriage return).
     */
    public static function stripControlCharacters(string $value): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    }

    /**
     * Similarity ratio between two strings, from 0.0 to 1.0.
     */
    public static function similarity(string $a, string $b): float
    {
        if ($a === '' && $b === '') {
            return 1.0;
        }

        similar_text(mb_strtolower($a, 'UTF-8'), mb_strtolower($b, 'UTF-8'), $percent);

        return round($percent / 100, 4);
    }

    /**
     * Format a byte count for display.
     */
    public static function bytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max((float) $bytes, 0.0);
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $precision) . ' ' . $units[$power];
    }

    /**
     * Format a duration in seconds as a compact human string (e.g. "2h 14m").
     */
    public static function duration(?int $seconds): string
    {
        if ($seconds === null || $seconds < 0) {
            return '—';
        }

        if ($seconds < 60) {
            return $seconds . 's';
        }

        $days    = intdiv($seconds, 86400);
        $hours   = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);

        $parts = [];
        if ($days > 0) {
            $parts[] = $days . 'd';
        }
        if ($hours > 0) {
            $parts[] = $hours . 'h';
        }
        if ($minutes > 0 && $days === 0) {
            $parts[] = $minutes . 'm';
        }

        return $parts === [] ? '0m' : implode(' ', $parts);
    }
}
