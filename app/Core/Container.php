<?php

declare(strict_types=1);

namespace App\Core;

use App\Exceptions\ContainerException;
use Closure;
use ReflectionClass;
use ReflectionException;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Dependency-injection container with constructor autowiring.
 *
 * Services declare their collaborators as constructor parameters; the
 * container resolves them recursively. This keeps controllers and services
 * free of `new` statements, which is what makes them independently testable.
 *
 * @package App\Core
 * @version 1.0.0
 */
class Container
{
    /** @var array<string,Closure> Factory bindings. */
    private array $bindings = [];

    /** @var array<string,bool> Abstracts that must only ever be built once. */
    private array $shared = [];

    /** @var array<string,mixed> Resolved singleton instances. */
    private array $instances = [];

    /** @var array<string,string> Interface => concrete implementation map. */
    private array $aliases = [];

    /** @var list<string> Resolution stack, used to detect circular dependencies. */
    private array $resolving = [];

    /**
     * Register a transient factory binding.
     */
    public function bind(string $abstract, Closure $factory): void
    {
        $this->bindings[$abstract] = $factory;
        unset($this->instances[$abstract], $this->shared[$abstract]);
    }

    /**
     * Register a binding that is built at most once per request.
     */
    public function singleton(string $abstract, ?Closure $factory = null): void
    {
        $this->bindings[$abstract] = $factory ?? fn (Container $c): mixed => $c->build($abstract);
        $this->shared[$abstract]   = true;
        unset($this->instances[$abstract]);
    }

    /**
     * Store an already-constructed object in the container.
     */
    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
        $this->shared[$abstract]    = true;
    }

    /**
     * Map an interface (or arbitrary key) onto a concrete class name.
     */
    public function alias(string $abstract, string $concrete): void
    {
        $this->aliases[$abstract] = $concrete;
    }

    public function bound(string $abstract): bool
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;

        return isset($this->bindings[$abstract]) || isset($this->instances[$abstract]);
    }

    /**
     * Resolve a service out of the container.
     *
     * @template T of object
     * @param class-string<T>|string $abstract
     * @param array<string,mixed>    $parameters Explicit constructor overrides.
     *
     * @return T|mixed
     *
     * @throws ContainerException When the service cannot be constructed.
     */
    public function make(string $abstract, array $parameters = []): mixed
    {
        $abstract = $this->aliases[$abstract] ?? $abstract;

        if (isset($this->instances[$abstract]) && $parameters === []) {
            return $this->instances[$abstract];
        }

        if (in_array($abstract, $this->resolving, true)) {
            throw new ContainerException(sprintf(
                'Circular dependency detected while resolving "%s" (chain: %s).',
                $abstract,
                implode(' -> ', [...$this->resolving, $abstract])
            ));
        }

        $this->resolving[] = $abstract;

        try {
            $object = isset($this->bindings[$abstract])
                ? ($this->bindings[$abstract])($this, $parameters)
                : $this->build($abstract, $parameters);
        } finally {
            array_pop($this->resolving);
        }

        if (($this->shared[$abstract] ?? false) && $parameters === []) {
            $this->instances[$abstract] = $object;
        }

        return $object;
    }

    /**
     * Instantiate a class, resolving each constructor dependency.
     *
     * @param array<string,mixed> $parameters
     *
     * @throws ContainerException
     */
    public function build(string $concrete, array $parameters = []): mixed
    {
        try {
            $reflection = new ReflectionClass($concrete);
        } catch (ReflectionException) {
            throw new ContainerException(sprintf('Class "%s" does not exist.', $concrete));
        }

        if (!$reflection->isInstantiable()) {
            throw new ContainerException(sprintf('Class "%s" is not instantiable.', $concrete));
        }

        $constructor = $reflection->getConstructor();
        if ($constructor === null) {
            return new $concrete();
        }

        $arguments = [];
        foreach ($constructor->getParameters() as $parameter) {
            $arguments[] = $this->resolveParameter($parameter, $parameters, $concrete);
        }

        return $reflection->newInstanceArgs($arguments);
    }

    /**
     * Resolve one constructor parameter.
     *
     * @param array<string,mixed> $overrides
     *
     * @throws ContainerException
     */
    private function resolveParameter(ReflectionParameter $parameter, array $overrides, string $owner): mixed
    {
        $name = $parameter->getName();

        if (array_key_exists($name, $overrides)) {
            return $overrides[$name];
        }

        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            /** @var class-string $dependency */
            $dependency = $type->getName();

            if ($dependency === self::class || is_subclass_of($dependency, self::class)) {
                return $this;
            }

            return $this->make($dependency);
        }

        if ($parameter->isDefaultValueAvailable()) {
            return $parameter->getDefaultValue();
        }

        if ($type instanceof ReflectionNamedType && $type->allowsNull()) {
            return null;
        }

        throw new ContainerException(sprintf(
            'Unable to resolve parameter "$%s" of %s::__construct().',
            $name,
            $owner
        ));
    }

    /**
     * Invoke a callable, autowiring any class-typed parameters.
     *
     * @param callable|array{0:object|string,1:string} $callback
     * @param array<string,mixed>                      $parameters
     *
     * @throws ContainerException
     */
    public function call(callable|array $callback, array $parameters = []): mixed
    {
        if (is_array($callback)) {
            [$target, $method] = $callback;
            $instance   = is_string($target) ? $this->make($target) : $target;

            try {
                $reflection = new \ReflectionMethod($instance, $method);
            } catch (ReflectionException $e) {
                throw new ContainerException($e->getMessage(), 0, $e);
            }

            $arguments = [];
            foreach ($reflection->getParameters() as $parameter) {
                $arguments[] = $this->resolveParameter($parameter, $parameters, $instance::class);
            }

            return $reflection->invokeArgs($instance, $arguments);
        }

        $reflection = new \ReflectionFunction($callback(...));
        $arguments  = [];
        foreach ($reflection->getParameters() as $parameter) {
            $arguments[] = $this->resolveParameter($parameter, $parameters, 'Closure');
        }

        return $reflection->invokeArgs($arguments);
    }

    /**
     * Forget a resolved singleton so the next make() rebuilds it.
     */
    public function forget(string $abstract): void
    {
        unset($this->instances[$abstract]);
    }
}
