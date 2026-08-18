<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Application;
use App\Core\ErrorHandler;
use App\Core\Routing\Route;
use App\Core\Routing\Router;
use App\Exceptions\ContainerException;
use App\Responses\ApiResponse;
use Closure;
use Throwable;

/**
 * HTTP kernel.
 *
 * Owns the request lifecycle: capture, resolve, run the middleware pipeline,
 * dispatch to the controller, and emit. The global middleware stack declared
 * here runs for every single request, before any route-specific middleware,
 * so there is no route that can be reached without it.
 *
 * @package App\Core\Http
 * @version 1.0.0
 */
final class Kernel
{
    /**
     * Middleware applied to every request, in order.
     *
     * The order matters. Runtime settings load first so every later stage
     * reads the administrator's values rather than the file defaults; security
     * headers attach next so they are present even on an error response; and
     * sanitisation runs before validation ever sees a value.
     *
     * @var list<class-string>
     */
    private const GLOBAL_MIDDLEWARE = [
        \App\Middleware\LoadRuntimeSettingsMiddleware::class,
        \App\Middleware\SecurityHeadersMiddleware::class,
        \App\Middleware\ForceHttpsMiddleware::class,
        \App\Middleware\RequestLoggingMiddleware::class,
        \App\Middleware\IpAllowlistMiddleware::class,
        \App\Middleware\FloodDetectionMiddleware::class,
        \App\Middleware\InputSanitizationMiddleware::class,
        \App\Middleware\MaintenanceModeMiddleware::class,
    ];

    public function __construct(
        private readonly Application $app,
        private readonly Router $router,
        private readonly ErrorHandler $errorHandler
    ) {
    }

    /**
     * Handle a request end to end and return the response.
     */
    public function handle(Request $request): Response
    {
        $this->errorHandler->setRequest($request);
        ApiResponse::bindRequest($request);

        // The request is registered in the container so middleware and
        // services can depend on it without it being threaded through every
        // constructor.
        $this->app->instance(Request::class, $request);

        try {
            $resolved = $this->router->resolve($request);
            /** @var Route $route */
            $route = $resolved['route'];

            $request->setRouteParameters($resolved['parameters']);
            $request->setAttribute('route', $route);
            $request->setAttribute('route_name', $route->getName());
            $request->setAttribute('required_permission', $route->getPermission());
            $request->setAttribute('rate_limit_bucket', $route->getRateLimitBucket());

            $middleware = array_values(array_unique(array_merge(
                self::GLOBAL_MIDDLEWARE,
                $route->middlewareStack()
            )));

            $response = (new Pipeline($this->app))
                ->send($request)
                ->through($middleware)
                ->then($this->dispatchToController($route));
        } catch (Throwable $e) {
            // The pipeline could not even be built or a middleware threw:
            // the error handler still produces a properly formatted response.
            $response = $this->errorHandler->render($e);

            // Global middleware never ran, so the security headers are applied
            // here to make sure an error response is protected too.
            $response = $this->applyEmergencyHeaders($response);
        }

        // A HEAD request shares the GET handler; the body is dropped here.
        if ($request->isMethod('HEAD')) {
            $response->setContent('');
        }

        return $response;
    }

    /**
     * Build the closure that invokes the route's controller action.
     *
     * @return Closure(Request):Response
     */
    private function dispatchToController(Route $route): Closure
    {
        return function (Request $request) use ($route): Response {
            $handler = $route->handler();

            if ($handler instanceof Closure) {
                /** @var mixed $result */
                $result = $this->app->call($handler, ['request' => $request]);

                return $this->toResponse($result);
            }

            if (!is_array($handler) || count($handler) !== 2) {
                throw new ContainerException('A route handler must be a closure or a [class, method] pair.');
            }

            [$class, $method] = $handler;

            if (!class_exists($class)) {
                throw new ContainerException(sprintf('Controller "%s" does not exist.', $class));
            }

            $controller = $this->app->make($class);

            if (!method_exists($controller, $method)) {
                throw new ContainerException(sprintf('Controller "%s" has no method "%s".', $class, $method));
            }

            /** @var mixed $result */
            $result = $this->app->call([$controller, $method], ['request' => $request]);

            return $this->toResponse($result);
        };
    }

    /**
     * Normalise whatever a controller returned into a Response.
     */
    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if (is_array($result)) {
            return new JsonResponse($result);
        }

        if ($result === null) {
            return Response::noContent();
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        throw new ContainerException(sprintf(
            'A controller action returned %s; expected a Response, array, string or null.',
            get_debug_type($result)
        ));
    }

    /**
     * Attach the mandatory security headers to a response produced outside the
     * normal pipeline.
     */
    private function applyEmergencyHeaders(Response $response): Response
    {
        /** @var array<string,string|null> $headers */
        $headers = (array) config('security.headers', []);

        foreach ($headers as $name => $value) {
            if ($value !== null && $response->header($name) === null) {
                $response->setHeader($name, (string) $value);
            }
        }

        return $response;
    }

    /**
     * Work performed after the response has been sent to the client.
     *
     * Anything here is invisible to the user, so it is the right place for
     * housekeeping that would otherwise add latency.
     */
    public function terminate(Request $request, Response $response): void
    {
        try {
            $this->app->make(\App\Services\ApiRequestLogService::class)
                ->finalise($request, $response);
        } catch (Throwable $e) {
            logger()->channel('api')->warning('Request log finalisation failed', [
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
