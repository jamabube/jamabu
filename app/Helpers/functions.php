<?php

declare(strict_types=1);

use App\Core\Application;
use App\Core\Config;
use App\Core\Env;
use App\Core\Support\Html;

/**
 * Global helper functions.
 *
 * These are deliberately few in number: they cover the operations that would
 * otherwise be repeated in nearly every template or class. Anything with real
 * behaviour lives in a class, not here.
 */

if (!function_exists('env')) {
    /**
     * Read an environment variable with a fallback default.
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Env::get($key, $default);
    }
}

if (!function_exists('config')) {
    /**
     * Read a configuration value using "file.key.subkey" dot notation.
     */
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('app')) {
    /**
     * Resolve a service from the application container.
     *
     * @template T of object
     * @param class-string<T>|null $abstract
     * @return ($abstract is null ? Application : T)
     */
    function app(?string $abstract = null): mixed
    {
        $application = Application::getInstance();

        return $abstract === null ? $application : $application->make($abstract);
    }
}

if (!function_exists('base_path')) {
    /**
     * Build an absolute path relative to the project root.
     */
    function base_path(string $path = ''): string
    {
        return Application::getInstance()->basePath($path);
    }
}

if (!function_exists('storage_path')) {
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path === '' ? '' : DIRECTORY_SEPARATOR . ltrim($path, '/\\')));
    }
}

if (!function_exists('e')) {
    /**
     * Escape a value for safe rendering inside HTML text or an attribute.
     *
     * Every value echoed from a template must pass through this function.
     */
    function e(mixed $value): string
    {
        return Html::escape($value);
    }
}

if (!function_exists('url')) {
    /**
     * Build an absolute URL for a path within the application.
     */
    function url(string $path = ''): string
    {
        return Application::getInstance()->url($path);
    }
}

if (!function_exists('route')) {
    /**
     * Build a URL for a named route.
     *
     * @param array<string,scalar> $parameters
     */
    function route(string $name, array $parameters = []): string
    {
        return Application::getInstance()->route($name, $parameters);
    }
}

if (!function_exists('asset')) {
    /**
     * Build a cache-busted URL for a first-party asset.
     */
    function asset(string $path): string
    {
        $version = (string) config('assets.version', '1.0.0');

        return url($path) . '?v=' . rawurlencode($version);
    }
}

if (!function_exists('brand_logo')) {
    /**
     * The URL of the organisation's logo.
     *
     * An installation belongs to a memorial park with its own mark, and the
     * shield drawn here is a stand-in until that file arrives. Dropping the
     * real one in as public/assets/img/brand.(svg|png|webp|jpg) replaces it
     * everywhere without touching a template — which matters because the
     * person with the logo is rarely the person editing PHP.
     *
     * SVG is preferred when several are present: it is the one that stays
     * sharp at the size the sign-in hero uses.
     */
    function brand_logo(): string
    {
        static $resolved = null;

        if ($resolved !== null) {
            return $resolved;
        }

        $public = Application::getInstance()->basePath('public');

        foreach (['svg', 'png', 'webp', 'jpg', 'jpeg'] as $extension) {
            $candidate = 'assets/img/brand.' . $extension;

            if (is_file($public . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $candidate))) {
                return $resolved = asset($candidate);
            }
        }

        return $resolved = asset('assets/img/logo.svg');
    }
}

if (!function_exists('old')) {
    /**
     * Retrieve previously submitted input after a failed validation round-trip.
     */
    function old(string $key, mixed $default = null): mixed
    {
        /** @var array<string,mixed> $input */
        $input = Application::getInstance()->make(\App\Core\Session::class)->getFlash('_old_input', []);

        return $input[$key] ?? $default;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Application::getInstance()->make(\App\Core\Security\CsrfGuard::class)->token();
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Render the hidden CSRF input required on every state-changing form.
     */
    function csrf_field(): string
    {
        $name = (string) config('security.csrf.token_name', '_csrf_token');

        return '<input type="hidden" name="' . e($name) . '" value="' . e(csrf_token()) . '">';
    }
}

if (!function_exists('method_field')) {
    /**
     * Render the hidden field that lets an HTML form issue PUT/PATCH/DELETE.
     */
    function method_field(string $method): string
    {
        return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
    }
}

if (!function_exists('auth')) {
    /**
     * The authenticated-user guard.
     */
    function auth(): \App\Core\Security\AuthGuard
    {
        return Application::getInstance()->make(\App\Core\Security\AuthGuard::class);
    }
}

if (!function_exists('can')) {
    /**
     * Check whether the signed-in user holds a permission.
     */
    function can(string $permission): bool
    {
        return auth()->can($permission);
    }
}

if (!function_exists('setting')) {
    /**
     * Read a runtime system setting (database backed, cached per request).
     */
    function setting(string $key, mixed $default = null): mixed
    {
        return Application::getInstance()
            ->make(\App\Services\SettingsService::class)
            ->get($key, $default);
    }
}

if (!function_exists('logger')) {
    function logger(): \App\Core\Logging\Logger
    {
        return Application::getInstance()->make(\App\Core\Logging\Logger::class);
    }
}

if (!function_exists('now')) {
    /**
     * Current time in the application timezone.
     */
    function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone((string) config('app.timezone', 'UTC')));
    }
}

if (!function_exists('array_get')) {
    /**
     * Read a nested array value using dot notation.
     *
     * @param array<string,mixed> $array
     */
    function array_get(array $array, string $key, mixed $default = null): mixed
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
}
