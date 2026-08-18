<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Environment variable loader.
 *
 * Reads a dotenv-style file into an in-memory store so that no credential ever
 * has to be hardcoded in source. Values are cast to native PHP types so that
 * configuration files receive booleans and integers rather than strings.
 *
 * @package App\Core
 * @author  VAMS Engineering Team
 * @version 1.0.0
 */
final class Env
{
    /** @var array<string,scalar|null> */
    private static array $values = [];

    private static bool $loaded = false;

    /**
     * Load a .env file into the environment store.
     *
     * Missing files are tolerated: the application then falls back to real
     * process environment variables, which is the expected behaviour when the
     * server injects configuration (for example through Apache SetEnv).
     *
     * @param string $path Absolute path to the environment file.
     */
    public static function load(string $path): void
    {
        self::$loaded = true;

        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip blank lines and comments.
            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            if ($key === '') {
                continue;
            }

            self::$values[$key] = self::normalise($value);
        }
    }

    /**
     * Retrieve an environment value with an optional fallback.
     *
     * @param string $key     Variable name.
     * @param mixed  $default Value returned when the variable is undefined.
     *
     * @return mixed
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        $native = getenv($key);
        if ($native !== false) {
            return self::normalise($native);
        }

        if (isset($_SERVER[$key])) {
            return self::normalise((string) $_SERVER[$key]);
        }

        return $default;
    }

    /**
     * Define (or override) a value at runtime. Used by the test harness.
     */
    public static function set(string $key, mixed $value): void
    {
        self::$values[$key] = $value;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /**
     * Strip quoting/inline comments and cast the raw string to a native type.
     */
    private static function normalise(string $raw): mixed
    {
        $value = trim($raw);

        // Quoted values keep their content verbatim (minus the quotes).
        if (strlen($value) > 1) {
            $first = $value[0];
            $last  = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        // Unquoted values may carry a trailing comment.
        if (str_contains($value, ' #')) {
            $value = trim(substr($value, 0, (int) strpos($value, ' #')));
        }

        return match (strtolower($value)) {
            'true', '(true)'   => true,
            'false', '(false)' => false,
            'null', '(null)'   => null,
            'empty', '(empty)' => '',
            default            => is_numeric($value) && !str_starts_with($value, '0x')
                ? (str_contains($value, '.') ? (float) $value : (int) $value)
                : $value,
        };
    }
}
