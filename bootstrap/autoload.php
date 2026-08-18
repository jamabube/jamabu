<?php

declare(strict_types=1);

/**
 * PSR-4 autoloader fallback.
 *
 * Used when Composer's autoloader is not present. It maps the "App\" and
 * "Tests\" namespaces onto app/ and tests/ exactly as composer.json declares,
 * so behaviour is identical either way.
 *
 * @package VAMS
 * @version 1.0.0
 */

$basePath = dirname(__DIR__);

/** @var array<string,string> Namespace prefix => directory. */
$prefixes = [
    'App\\'   => $basePath . '/app/',
    'Tests\\' => $basePath . '/tests/',
];

spl_autoload_register(static function (string $class) use ($prefixes): void {
    foreach ($prefixes as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }

        $relative = substr($class, strlen($prefix));
        $path     = $directory . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';

        if (is_file($path)) {
            require $path;

            return;
        }
    }
});

// composer.json declares this file under "autoload.files"; the fallback has to
// load it explicitly.
require $basePath . '/app/Helpers/functions.php';
