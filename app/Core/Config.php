<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Configuration repository.
 *
 * Loads every PHP file inside the config directory once per request and
 * exposes the merged tree through dot notation ("security.password.min_length").
 *
 * @package App\Core
 * @version 1.0.0
 */
final class Config
{
    /** @var array<string,mixed> */
    private static array $items = [];

    private static bool $loaded = false;

    /**
     * Load every configuration file from a directory.
     *
     * @param string $directory Absolute path to the config directory.
     *
     * @throws RuntimeException When the directory cannot be read.
     */
    public static function load(string $directory): void
    {
        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf('Configuration directory "%s" does not exist.', $directory));
        }

        $files = glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [];

        foreach ($files as $file) {
            $name = basename($file, '.php');

            /** @var mixed $contents */
            $contents = require $file;

            if (!is_array($contents)) {
                throw new RuntimeException(sprintf('Configuration file "%s" must return an array.', $file));
            }

            // A "local.php" override file is merged over the top of everything
            // else so operators can adjust a deployment without editing tracked
            // files. It is excluded from version control.
            if ($name === 'local') {
                self::$items = array_replace_recursive(self::$items, $contents);
                continue;
            }

            self::$items[$name] = array_replace_recursive(self::$items[$name] ?? [], $contents);
        }

        self::$loaded = true;
    }

    /**
     * Read a configuration value using dot notation.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }

    /**
     * Override a configuration value for the lifetime of the request.
     *
     * Used by the System Settings module to overlay database-backed values on
     * top of the file defaults, and by the test harness.
     */
    public static function set(string $key, mixed $value): void
    {
        $segments = explode('.', $key);
        $cursor    = &self::$items;

        foreach ($segments as $index => $segment) {
            if ($index === count($segments) - 1) {
                $cursor[$segment] = $value;
                break;
            }

            if (!isset($cursor[$segment]) || !is_array($cursor[$segment])) {
                $cursor[$segment] = [];
            }

            $cursor = &$cursor[$segment];
        }
    }

    public static function has(string $key): bool
    {
        return self::get($key, '__vams_missing__') !== '__vams_missing__';
    }

    /**
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        return self::$items;
    }

    public static function isLoaded(): bool
    {
        return self::$loaded;
    }

    /**
     * Discard every loaded value. Only used between test cases.
     */
    public static function flush(): void
    {
        self::$items = [];
        self::$loaded = false;
    }
}
