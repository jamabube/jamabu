<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Application;
use App\Exceptions\ValidationException;
use App\Exceptions\VamsException;
use Throwable;

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
     * Report a failure with the detail the exception is carrying.
     *
     * A command that prints only getMessage() throws away the part the
     * operator needs. "The submitted data is invalid." is the whole of what a
     * validation failure says on its own, while the reasons it was invalid sit
     * unread in the exception — and at a shell prompt there is no form to
     * flash them back to.
     */
    protected function reportFailure(Throwable $e): void
    {
        $this->output->error($e->getMessage());

        if ($e instanceof ValidationException) {
            foreach ($e->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->output->comment('        ' . $message);
                }
            }

            return;
        }

        if ($e instanceof VamsException) {
            $driverMessage = $e->context()['driver_message'] ?? null;

            if (is_string($driverMessage) && $driverMessage !== '') {
                $this->output->comment('        ' . $driverMessage);
            }
        }
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
