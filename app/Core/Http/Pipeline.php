<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Container;
use App\Middleware\MiddlewareInterface;
use App\Exceptions\ContainerException;
use Closure;

/**
 * Middleware pipeline.
 *
 * Builds a nested chain of middleware around the final route handler. Every
 * request traverses the full chain; there is no mechanism for a route to skip
 * a middleware that its group declared.
 *
 * @package App\Core\Http
 * @version 1.0.0
 */
final class Pipeline
{
    /** @var list<string> Middleware class names, outermost first. */
    private array $middleware = [];

    private Request $request;

    public function __construct(private readonly Container $container)
    {
    }

    public function send(Request $request): self
    {
        $this->request = $request;

        return $this;
    }

    /**
     * @param list<string> $middleware Middleware class names.
     */
    public function through(array $middleware): self
    {
        $this->middleware = $middleware;

        return $this;
    }

    /**
     * Run the pipeline, ending at $destination.
     *
     * @param Closure(Request):Response $destination
     */
    public function then(Closure $destination): Response
    {
        // array_reduce over the reversed stack builds the chain from the inside
        // out, so the first declared middleware ends up outermost.
        $chain = array_reduce(
            array_reverse($this->middleware),
            function (Closure $next, string $middlewareClass): Closure {
                return function (Request $request) use ($next, $middlewareClass): Response {
                    $middleware = $this->container->make($middlewareClass);

                    if (!$middleware instanceof MiddlewareInterface) {
                        throw new ContainerException(sprintf(
                            'Middleware "%s" must implement %s.',
                            $middlewareClass,
                            MiddlewareInterface::class
                        ));
                    }

                    return $middleware->handle($request, $next);
                };
            },
            $destination
        );

        return $chain($this->request);
    }
}
