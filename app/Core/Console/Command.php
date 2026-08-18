<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Application;

/**
 * Base class for console commands.
 *
 * @package App\Core\Console
 * @version 1.0.0
 */
abstract class Command
{
    /** Command name as typed, e.g. "migrate:rollback". */
    protected string $name = '';

    /** One-line description shown by the command list. */
    protected string $description = '';

    /** Usage line shown by --help. */
    protected string $usage = '';

    /** @var list<string> Positional arguments passed after the command name. */
    protected array $arguments = [];

    /** @var array<string,string|bool> Parsed --options. */
    protected array $options = [];

    public function __construct(
        protected readonly Application $app,
        protected readonly Output $output
    ) {
    }

    /**
     * Execute the command.
     *
     * @return int Process exit code: 0 on success.
     */
    abstract public function handle(): int;

    public function name(): string
    {
        return $this->name;
    }

    public function description(): string
    {
        return $this->description;
    }

    public function usage(): string
    {
        return $this->usage === '' ? 'php bin/console ' . $this->name : $this->usage;
    }

    /**
     * @param list<string>              $arguments
     * @param array<string,string|bool> $options
     */
    public function setInput(array $arguments, array $options): void
    {
        $this->arguments = $arguments;
        $this->options   = $options;
    }

    protected function argument(int $position, ?string $default = null): ?string
    {
        return $this->arguments[$position] ?? $default;
    }

    protected function option(string $name, string|bool|null $default = null): string|bool|null
    {
        return $this->options[$name] ?? $default;
    }

    protected function hasOption(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    protected function optionInt(string $name, int $default = 0): int
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) && is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Whether the operator passed --force, which suppresses confirmations.
     * Required for any destructive command run from a scheduler.
     */
    protected function isForced(): bool
    {
        return $this->hasOption('force');
    }

    /**
     * Resolve a service from the container.
     *
     * @template T of object
     * @param class-string<T> $service
     *
     * @return T
     */
    protected function service(string $service): object
    {
        /** @var T $instance */
        $instance = $this->app->make($service);

        return $instance;
    }
}
