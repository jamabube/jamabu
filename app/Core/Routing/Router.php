<?php

declare(strict_types=1);

namespace App\Core\Routing;

use App\Core\Http\Request;
use App\Exceptions\HttpException;
use App\Exceptions\NotFoundException;
use Closure;
use RuntimeException;

/**
 * Route table and dispatcher.
 *
 * Routes are grouped so that a whole module inherits a URI prefix and a
 * middleware stack in one place; a route can never accidentally be registered
 * without the group's protections.
 *
 * @package App\Core\Routing
 * @version 1.0.0
 */
class Router
{
    /** @var array<string,list<Route>> Routes indexed by HTTP verb. */
    private array $routes = [
        'GET'     => [],
        'POST'    => [],
        'PUT'     => [],
        'PATCH'   => [],
        'DELETE'  => [],
        'OPTIONS' => [],
        'HEAD'    => [],
    ];

    /** @var array<string,Route> Named routes for URL generation. */
    private array $named = [];

    /** @var array{prefix:string,middleware:list<string>,name:string,permission:?string} */
    private array $groupState = ['prefix' => '', 'middleware' => [], 'name' => '', 'permission' => null];

    /** @var list<array{prefix:string,middleware:list<string>,name:string,permission:?string}> */
    private array $groupStack = [];

    /**
     * Register a route group. Attributes accumulate for the duration of the
     * callback.
     *
     * @param array{prefix?:string,middleware?:list<string>|string,name?:string,permission?:string} $attributes
     */
    public function group(array $attributes, Closure $callback): void
    {
        $this->groupStack[] = $this->groupState;

        $this->groupState = [
            'prefix'     => rtrim($this->groupState['prefix'], '/') . '/' . trim((string) ($attributes['prefix'] ?? ''), '/'),
            'middleware' => array_values(array_unique(array_merge(
                $this->groupState['middleware'],
                (array) ($attributes['middleware'] ?? [])
            ))),
            'name'       => $this->groupState['name'] . (string) ($attributes['name'] ?? ''),
            'permission' => $attributes['permission'] ?? $this->groupState['permission'],
        ];

        $callback($this);

        $restored = array_pop($this->groupStack);
        if ($restored === null) {
            throw new RuntimeException('Route group stack underflow.');
        }
        $this->groupState = $restored;
    }

    /**
     * @param array{0:class-string,1:string}|callable $handler
     */
    public function get(string $uri, array|callable $handler): Route
    {
        return $this->addRoute('GET', $uri, $handler);
    }

    /**
     * @param array{0:class-string,1:string}|callable $handler
     */
    public function post(string $uri, array|callable $handler): Route
    {
        return $this->addRoute('POST', $uri, $handler);
    }

    /**
     * @param array{0:class-string,1:string}|callable $handler
     */
    public function put(string $uri, array|callable $handler): Route
    {
        return $this->addRoute('PUT', $uri, $handler);
    }

    /**
     * @param array{0:class-string,1:string}|callable $handler
     */
    public function patch(string $uri, array|callable $handler): Route
    {
        return $this->addRoute('PATCH', $uri, $handler);
    }

    /**
     * @param array{0:class-string,1:string}|callable $handler
     */
    public function delete(string $uri, array|callable $handler): Route
    {
        return $this->addRoute('DELETE', $uri, $handler);
    }

    /**
     * Register the same handler for several verbs.
     *
     * @param list<string>                            $methods
     * @param array{0:class-string,1:string}|callable $handler
     *
     * @return list<Route>
     */
    public function match(array $methods, string $uri, array|callable $handler): array
    {
        $routes = [];
        foreach ($methods as $method) {
            $routes[] = $this->addRoute(strtoupper($method), $uri, $handler);
        }

        return $routes;
    }

    /**
     * @param array{0:class-string,1:string}|callable $handler
     */
    private function addRoute(string $method, string $uri, array|callable $handler): Route
    {
        $fullUri = rtrim($this->groupState['prefix'], '/') . '/' . ltrim($uri, '/');
        $fullUri = '/' . trim($fullUri, '/');

        $route = new Route(
            $method,
            $fullUri === '/' ? '/' : $fullUri,
            $handler,
            $this,
            $this->groupState['name']
        );

        $route->middleware($this->groupState['middleware']);

        if ($this->groupState['permission'] !== null) {
            $route->permission($this->groupState['permission']);
        }

        $this->routes[$method][] = $route;

        return $route;
    }

    /**
     * Register a route in the named-route index.
     */
    public function registerName(string $name, Route $route): void
    {
        if (isset($this->named[$name])) {
            throw new RuntimeException(sprintf('Duplicate route name "%s".', $name));
        }

        $this->named[$name] = $route;
    }

    /**
     * Resolve the route matching a request.
     *
     * @return array{route:Route,parameters:array<string,string>}
     *
     * @throws NotFoundException When no route matches the path.
     * @throws HttpException     405 when the path matches under a different verb.
     */
    public function resolve(Request $request): array
    {
        $method = $request->method();
        $path   = '/' . trim($request->path(), '/');

        // HEAD is served by the GET handler with the body suppressed later.
        $lookupMethod = $method === 'HEAD' ? 'GET' : $method;

        foreach ($this->routes[$lookupMethod] ?? [] as $route) {
            $parameters = $route->match($path);
            if ($parameters !== null) {
                return ['route' => $route, 'parameters' => $parameters];
            }
        }

        // Distinguish "wrong verb" from "no such resource" so clients receive
        // an accurate status code.
        $allowed = [];
        foreach ($this->routes as $verb => $routes) {
            if ($verb === $lookupMethod) {
                continue;
            }
            foreach ($routes as $route) {
                if ($route->match($path) !== null) {
                    $allowed[] = $verb;
                    break;
                }
            }
        }

        if ($allowed !== []) {
            throw new HttpException(
                405,
                sprintf('The %s method is not supported for this endpoint.', $method),
                'METHOD_NOT_ALLOWED',
                ['allowed_methods' => array_values(array_unique($allowed))]
            );
        }

        throw new NotFoundException('The requested endpoint does not exist.');
    }

    /**
     * Generate a URL for a named route.
     *
     * @param array<string,scalar> $parameters
     *
     * @throws RuntimeException When the route name is unknown.
     */
    public function url(string $name, array $parameters = []): string
    {
        if (!isset($this->named[$name])) {
            throw new RuntimeException(sprintf('Route "%s" is not defined.', $name));
        }

        return $this->named[$name]->url($parameters);
    }

    public function hasName(string $name): bool
    {
        return isset($this->named[$name]);
    }

    /**
     * @return array<string,Route>
     */
    public function namedRoutes(): array
    {
        return $this->named;
    }

    /**
     * Every registered route, flattened. Used by the route:list command and by
     * the security audit that verifies each route declares its protection.
     *
     * @return list<Route>
     */
    public function all(): array
    {
        $all = [];
        foreach ($this->routes as $routes) {
            foreach ($routes as $route) {
                $all[] = $route;
            }
        }

        return $all;
    }
}
