<?php

declare(strict_types=1);

/**
 * Router for PHP's built-in development server.
 *
 *     php -S 127.0.0.1:8000 -t public public/router.php
 *
 * Apache and XAMPP never load this file — public/.htaccess already serves a
 * real file when one exists and rewrites everything else to index.php. The
 * built-in server has no such rule, so this reproduces it: return false for a
 * file that exists on disk, and hand everything else to the front controller.
 *
 * @package VAMS
 * @version 1.0.0
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = __DIR__ . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);

// Serve a real file directly, but never a PHP script other than the front
// controller: the built-in server would otherwise execute anything reachable
// under the document root.
if ($path !== '/' && is_file($file) && !str_ends_with(strtolower($file), '.php')) {
    return false;
}

// The front controller derives its base path from SCRIPT_NAME. Under the
// built-in server that would otherwise be the requested URI rather than the
// script, which would make the application strip a prefix that is not there.
$_SERVER['SCRIPT_NAME']     = '/index.php';
$_SERVER['SCRIPT_FILENAME'] = __DIR__ . DIRECTORY_SEPARATOR . 'index.php';
$_SERVER['PHP_SELF']        = '/index.php';

require __DIR__ . DIRECTORY_SEPARATOR . 'index.php';
