<?php

declare(strict_types=1);

namespace App\Core\Console;

use App\Core\Application;
use App\Exceptions\VamsException;
use Throwable;

/**
 * Console kernel: parses the command line, resolves a command and runs it.
 *
 * @package App\Core\Console
 * @version 1.0.0
 */
final class ConsoleKernel
{
    /**
     * Registered commands, name => class.
     *
     * @var array<string,class-string<Command>>
     */
    private const COMMANDS = [
        'migrate'           => \App\Console\Commands\MigrateCommand::class,
        'migrate:rollback'  => \App\Console\Commands\MigrateRollbackCommand::class,
        'migrate:status'    => \App\Console\Commands\MigrateStatusCommand::class,
        'migrate:fresh'     => \App\Console\Commands\MigrateFreshCommand::class,
        'seed'              => \App\Console\Commands\SeedCommand::class,
        'install'           => \App\Console\Commands\InstallCommand::class,
        'key:generate'      => \App\Console\Commands\KeyGenerateCommand::class,
        'user:create'       => \App\Console\Commands\UserCreateCommand::class,
        'user:password'     => \App\Console\Commands\UserPasswordCommand::class,
        'device:register'   => \App\Console\Commands\DeviceRegisterCommand::class,
        'device:rotate-key' => \App\Console\Commands\DeviceRotateKeyCommand::class,
        'device:check'      => \App\Console\Commands\DeviceCheckCommand::class,
        'backup:create'     => \App\Console\Commands\BackupCreateCommand::class,
        'backup:restore'    => \App\Console\Commands\BackupRestoreCommand::class,
        'backup:list'       => \App\Console\Commands\BackupListCommand::class,
        'maintenance:run'   => \App\Console\Commands\MaintenanceRunCommand::class,
        'log:prune'         => \App\Console\Commands\LogPruneCommand::class,
        'route:list'        => \App\Console\Commands\RouteListCommand::class,
        'security:audit'    => \App\Console\Commands\SecurityAuditCommand::class,
        'assets:fetch'      => \App\Console\Commands\AssetsFetchCommand::class,
        'doctor'            => \App\Console\Commands\DoctorCommand::class,
        'test'              => \App\Console\Commands\TestCommand::class,
    ];

    private Output $output;

    public function __construct(private readonly Application $app)
    {
        $this->output = new Output();
    }

    /**
     * Run the console application.
     *
     * @param list<string> $argv Raw command-line arguments including the script name.
     *
     * @return int Process exit code.
     */
    public function run(array $argv): int
    {
        $parsed  = $this->parse(array_slice($argv, 1));
        $command = $parsed['command'];

        if ($command === null || $command === 'list' || $command === 'help') {
            $this->listCommands();

            return 0;
        }

        if (!isset(self::COMMANDS[$command])) {
            $this->output->error(sprintf('Unknown command "%s".', $command));
            $this->suggest($command);

            return 1;
        }

        /** @var class-string<Command> $class */
        $class = self::COMMANDS[$command];

        // Everything a command does is attributed to the operating-system
        // account that ran it, which is the only identity a shell has. This
        // grants no authority on its own: a command that needs to act where
        // RBAC expects a signed-in user asks for it explicitly, and only
        // around the action that needs it, through withSystemAuthority().
        $this->app->make(\App\Core\Security\AuthGuard::class)->actAsConsole($this->operatingSystemUser());

        try {
            // Console commands read the same overlaid configuration a web
            // request does, so a scheduled task honours the administrator's
            // settings rather than the file defaults.
            $this->app->make(\App\Services\SettingsService::class)->applyToConfiguration();
        } catch (Throwable $e) {
            $this->output->warning('Runtime settings unavailable; using configuration defaults. ' . $e->getMessage());
        }

        try {
            /** @var Command $instance */
            $instance = new $class($this->app, $this->output);
            $instance->setInput($parsed['arguments'], $parsed['options']);

            if (isset($parsed['options']['help'])) {
                $this->output->line($instance->description());
                $this->output->line('Usage: ' . $instance->usage());

                return 0;
            }

            return $instance->handle();
        } catch (Throwable $e) {
            $this->output->error($e->getMessage());

            // The exception classes hide driver-level detail from a client
            // response, because it can carry SQL and schema. At a shell prompt
            // there is no client: the reader is the administrator, and the
            // sanitised message on its own ("Database operation \"connect\"
            // failed.") tells them nothing they can act on.
            $this->explain($e);

            if ($this->app->isDebug()) {
                $this->output->comment($e->getTraceAsString());
            }

            // Console failures are logged too: a scheduled backup that fails
            // at 02:00 must leave a trace an administrator can find.
            try {
                logger()->channel('application')->error('Console command failed', [
                    'command'   => $command,
                    'exception' => $e::class,
                    'message'   => $e->getMessage(),
                ]);
            } catch (Throwable) {
                // Logging is best effort here; the message is already on stderr.
            }

            return 1;
        }
    }

    /**
     * Parse arguments into a command name, positional arguments and options.
     *
     * @param list<string> $argv
     *
     * @return array{command:?string,arguments:list<string>,options:array<string,string|bool>}
     */
    private function parse(array $argv): array
    {
        $command   = null;
        $arguments = [];
        $options   = [];

        foreach ($argv as $token) {
            if (str_starts_with($token, '--')) {
                $body = substr($token, 2);

                if (str_contains($body, '=')) {
                    [$name, $value] = explode('=', $body, 2);
                    $options[$name] = $value;
                    continue;
                }

                $options[$body] = true;
                continue;
            }

            if (str_starts_with($token, '-') && strlen($token) > 1) {
                foreach (str_split(substr($token, 1)) as $flag) {
                    $options[$flag] = true;
                }
                continue;
            }

            if ($command === null) {
                $command = $token;
                continue;
            }

            $arguments[] = $token;
        }

        return ['command' => $command, 'arguments' => $arguments, 'options' => $options];
    }

    /**
     * Print the available commands grouped by namespace.
     */
    private function listCommands(): void
    {
        $this->output->title(sprintf(
            '%s console  —  version %s',
            (string) config('app.name', 'VAMS'),
            $this->app->version()
        ));

        $this->output->line('Usage: php bin/console <command> [arguments] [--options]');
        $this->output->line();

        $groups = [];
        foreach (self::COMMANDS as $name => $class) {
            $namespace = str_contains($name, ':') ? strstr($name, ':', true) : 'general';
            $groups[(string) $namespace][$name] = $class;
        }

        ksort($groups);

        foreach ($groups as $namespace => $commands) {
            $this->output->line($this->output->colour(ucfirst($namespace), 'bold', 'yellow'));

            foreach ($commands as $name => $class) {
                $description = class_exists($class)
                    ? (new $class($this->app, $this->output))->description()
                    : '';

                $this->output->line(sprintf(
                    '  %s %s',
                    $this->output->colour(str_pad($name, 22), 'green'),
                    $description
                ));
            }

            $this->output->line();
        }
    }

    /**
     * Print what a sanitised exception message left out.
     *
     * The underlying driver message first, then the context the exception
     * carries, then whatever the original throwable said if it differs. For a
     * failed connection this is the difference between "it failed" and
     * "Access denied for user 'vams_app'@'localhost'", which names the fix.
     */
    private function explain(Throwable $e): void
    {
        $printed = [$e->getMessage() => true];

        if ($e instanceof VamsException) {
            $context = $e->context();

            $driverMessage = $context['driver_message'] ?? null;

            if (is_string($driverMessage) && $driverMessage !== '') {
                $this->output->comment('        ' . $driverMessage);
                $printed[$driverMessage] = true;
            }

            foreach ($context as $key => $value) {
                if ($key === 'driver_message' || !is_scalar($value) || (string) $value === '') {
                    continue;
                }

                $this->output->comment(sprintf('        %-10s %s', $key, (string) $value));
            }
        }

        $previous = $e->getPrevious();

        while ($previous !== null) {
            $message = $previous->getMessage();

            if ($message !== '' && !isset($printed[$message])) {
                $this->output->comment('        ' . $message);
                $printed[$message] = true;
            }

            $previous = $previous->getPrevious();
        }
    }

    /**
     * The account that invoked the process.
     *
     * POSIX, then the environment, then a plain fallback: on Windows neither
     * posix_getpwuid nor USER exists, and an audit record saying "unknown" is
     * better than one that fails to write.
     */
    private function operatingSystemUser(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $record = posix_getpwuid(posix_geteuid());

            if (is_array($record) && isset($record['name']) && $record['name'] !== '') {
                return (string) $record['name'];
            }
        }

        foreach (['USER', 'USERNAME', 'LOGNAME'] as $variable) {
            $value = getenv($variable);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return 'unknown';
    }

    /**
     * Offer the closest matching command name after a typo.
     */
    private function suggest(string $attempted): void
    {
        $best     = null;
        $distance = PHP_INT_MAX;

        foreach (array_keys(self::COMMANDS) as $name) {
            $candidate = levenshtein($attempted, $name);

            if ($candidate < $distance) {
                $distance = $candidate;
                $best     = $name;
            }
        }

        if ($best !== null && $distance <= 4) {
            $this->output->comment(sprintf('Did you mean "%s"?', $best));
        }

        $this->output->comment('Run "php bin/console list" to see the available commands.');
    }

    /**
     * @return array<string,class-string<Command>>
     */
    public static function commands(): array
    {
        return self::COMMANDS;
    }
}
