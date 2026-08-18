<?php

declare(strict_types=1);

/**
 * Front controller.
 *
 * Every HTTP request to the application enters here. The web server's document
 * root points at this directory, so nothing outside public/ — configuration,
 * logs, backups, source code — is reachable over HTTP.
 *
 * @package VAMS
 * @version 1.0.0
 */

use App\Core\Application;
use App\Core\ErrorHandler;
use App\Core\Http\Kernel;
use App\Core\Http\Request;
use App\Core\Logging\Logger;
use App\Core\Routing\Router;
use App\Core\View\ViewEngine;

define('VAMS_START', microtime(true));

$basePath = dirname(__DIR__);

// ---------------------------------------------------------------------------
// Autoloading. Composer is preferred; the fallback keeps the application
// runnable on a machine where `composer install` has not been run yet, which
// matters for a LAN deployment performed from a USB stick.
// ---------------------------------------------------------------------------
if (is_file($basePath . '/vendor/autoload.php')) {
    require $basePath . '/vendor/autoload.php';
} else {
    require $basePath . '/bootstrap/autoload.php';
}

// ---------------------------------------------------------------------------
// Boot the application and install the error handler before anything else can
// fail, so even a configuration error produces a proper response.
// ---------------------------------------------------------------------------
$app = Application::create($basePath)->boot();

$errorHandler = new ErrorHandler(
    $app,
    $app->make(Logger::class),
    $app->make(ViewEngine::class)
);
$errorHandler->register();
$app->instance(ErrorHandler::class, $errorHandler);

// ---------------------------------------------------------------------------
// Route table, then dispatch.
// ---------------------------------------------------------------------------
$app->loadRoutes();

$kernel = new Kernel($app, $app->make(Router::class), $errorHandler);
$app->instance(Kernel::class, $kernel);

$request  = Request::capture();
$response = $kernel->handle($request);

$response->send();

$kernel->terminate($request, $response);
