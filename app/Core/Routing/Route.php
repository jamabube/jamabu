<?php

declare(strict_types=1);

namespace App\Core\Routing;

/**
 * A single route definition.
 *
 * Beyond the usual verb/URI/handler triple, a route also declares the
 * middleware it needs, the permission required to reach it, and the rate-limit
 * bucket it draws from. Declaring these on the route (rather than inside the
 * controller) is what makes it possible to audit at a glance that no endpoint
 * is left unprotected.
 *
 * @package App\Core\Routing
 * @version 1.0.0
 */
final class Route
{
    /** @var list<string> */
    private array $middleware = [];

    private ?string $name = null;
    private ?string $permission = null;
    private ?string $rateLimitBucket = null;
    private string $compiledPattern;

    /** @var list<string> Parameter names in the order they appear in the URI. */
    private array $parameterNames = [];

    /** @var array<string,string> Regular-expression constraints per parameter. */
    private array $constraints = [];

    /**
     * @param string                                  $method     HTTP verb.
     * @param string                                  $uri        URI pattern, e.g. "/vehicles/{id}".
     * @param array{0:class-string,1:string}|callable $handler    Controller/method pair or closure.
     * @param Router|null                             $router     Owning router, for name registration.
     * @param string                                  $namePrefix Prefix contributed by the enclosing group.
     */
    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly mixed $handler,
        private readonly ?Router $router = null,
        private readonly string $namePrefix = ''
    ) {
        $this->compiledPattern = $this->compile($uri);
    }

    /**
     * Translate "/vehicles/{id}" into an anchored regular expression.
     */
    private function compile(string $uri): string
    {
        $this->parameterNames = [];

        $pattern = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::([^}]+))?\}/',
            function (array $matches): string {
                $name = $matches[1];
                $this->parameterNames[] = $name;

                // A route may inline its own constraint: {id:\d+}
                $constraint = $matches[2] ?? ($this->constraints[$name] ?? '[^/]+');

                return '(?P<' . $name . '>' . $constraint . ')';
            },
            '/' . trim($uri, '/')
        );

        return '#^' . ($pattern === '/' ? '/' : rtrim((string) $pattern, '/')) . '/?$#';
    }

    /**
     * Constrain a parameter with a regular expression.
     */
    public function where(string $parameter, string $expression): self
    {
        $this->constraints[$parameter] = $expression;
        $this->compiledPattern = $this->compile($this->uri);

        return $this;
    }

    /**
     * Constrain a parameter to a positive integer.
     */
    public function whereNumber(string $parameter): self
    {
        return $this->where($parameter, '[0-9]+');
    }

    /**
     * @param list<string>|string $middleware
     */
    public function middleware(array|string $middleware): self
    {
        foreach ((array) $middleware as $item) {
            if (!in_array($item, $this->middleware, true)) {
                $this->middleware[] = $item;
            }
        }

        return $this;
    }

    /**
     * Name the route so URLs can be generated for it. The enclosing group's
     * name prefix is applied automatically.
     */
    public function name(string $name): self
    {
        $this->name = $this->namePrefix . $name;
        $this->router?->registerName($this->name, $this);

        return $this;
    }

    /**
     * Declare the permission a user must hold to reach this route.
     */
    public function permission(string $permission): self
    {
        $this->permission = $permission;

        return $this;
    }

    /**
     * Draw this route's rate limit from a named bucket in config/api.php.
     */
    public function throttle(string $bucket): self
    {
        $this->rateLimitBucket = $bucket;

        return $this;
    }

    /**
     * Attempt to match a request path, returning the extracted parameters.
     *
     * @return array<string,string>|null Null when the path does not match.
     */
    public function match(string $path): ?array
    {
        if (preg_match($this->compiledPattern, $path, $matches) !== 1) {
            return null;
        }

        $parameters = [];
        foreach ($this->parameterNames as $name) {
            if (isset($matches[$name])) {
                $parameters[$name] = $matches[$name];
            }
        }

        return $parameters;
    }

    /**
     * Build a concrete URL from this route's pattern.
     *
     * @param array<string,scalar> $parameters
     */
    public function url(array $parameters = []): string
    {
        $uri = preg_replace_callback(
            '/\{([a-zA-Z_][a-zA-Z0-9_]*)(?::[^}]+)?\}/',
            static fn (array $m): string => rawurlencode((string) ($parameters[$m[1]] ?? '')),
            '/' . trim($this->uri, '/')
        );

        // Any parameter not consumed by the pattern becomes a query argument.
        $used  = $this->parameterNames;
        $extra = array_diff_key($parameters, array_flip($used));

        $query = $extra === [] ? '' : '?' . http_build_query($extra);

        return ($uri === '' ? '/' : (string) $uri) . $query;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function uri(): string
    {
        return $this->uri;
    }

    public function handler(): mixed
    {
        return $this->handler;
    }

    /**
     * @return list<string>
     */
    public function middlewareStack(): array
    {
        return $this->middleware;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function getPermission(): ?string
    {
        return $this->permission;
    }

    public function getRateLimitBucket(): ?string
    {
        return $this->rateLimitBucket;
    }
}
