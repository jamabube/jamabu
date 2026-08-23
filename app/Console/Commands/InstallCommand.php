<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Database\Connection;
use App\Core\Database\MigrationRunner;
use PDO;
use PDOException;
use Throwable;

/**
 * Set the system up from a fresh checkout.
 *
 * Runs the steps an administrator would otherwise run in order and get wrong
 * in the middle: check the runtime, write .env, generate the key, create the
 * database, migrate, seed, and report what is left to do.
 *
 * Safe to run twice. Every step checks the state it is about to change and
 * says "already done" rather than repeating it, because the most likely reason
 * somebody runs this a second time is that it failed halfway the first time.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class InstallCommand extends Command
{
    protected string $name = 'install';
    protected string $description = 'Prepare a fresh installation: environment, key, database, schema and seed data.';
    protected string $usage = 'php bin/console install [--demo] [--force]';

    /** PHP extensions without which the system cannot run. */
    private const REQUIRED_EXTENSIONS = ['pdo_mysql', 'mbstring', 'json', 'openssl', 'fileinfo'];

    /** Extensions the system works without, at a cost stated to the operator. */
    private const OPTIONAL_EXTENSIONS = [
        'zip'   => 'backups are written uncompressed',
        'gd'    => 'profile pictures are stored without being resized',
        'intl'  => 'number and date formatting falls back to the built-in rules',
        'curl'  => 'assets:fetch falls back to the stream wrapper',
    ];

    private const MINIMUM_PHP = '8.2.0';

    public function handle(): int
    {
        $this->output->title('Installing the vehicle access monitoring system');

        if (!$this->checkRuntime()) {
            return 1;
        }

        if (!$this->checkDirectories()) {
            return 1;
        }

        if (!$this->ensureEnvironmentFile()) {
            return 1;
        }

        if (!$this->ensureApplicationKey()) {
            return 1;
        }

        if (!$this->ensureDatabase()) {
            return 1;
        }

        if (!$this->migrate()) {
            return 1;
        }

        if (!$this->seed()) {
            return 1;
        }

        $this->reportAssets();
        $this->closingNotes();

        return 0;
    }

    // ------------------------------------------------------------------
    // Steps
    // ------------------------------------------------------------------

    private function checkRuntime(): bool
    {
        $this->output->info('1/7  Runtime');

        $ok = true;

        if (version_compare(PHP_VERSION, self::MINIMUM_PHP, '<')) {
            $this->output->error(sprintf(
                '        PHP %s or later is required; this is %s.',
                self::MINIMUM_PHP,
                PHP_VERSION
            ));

            $ok = false;
        } else {
            $this->output->comment('        PHP ' . PHP_VERSION);
        }

        $missing = array_values(array_filter(
            self::REQUIRED_EXTENSIONS,
            static fn (string $extension): bool => !extension_loaded($extension)
        ));

        if ($missing !== []) {
            $this->output->error('        Missing required extension(s): ' . implode(', ', $missing));
            $this->output->comment('        Enable them in php.ini and run this again.');

            $ok = false;
        } else {
            $this->output->comment('        Required extensions present');
        }

        foreach (self::OPTIONAL_EXTENSIONS as $extension => $consequence) {
            if (!extension_loaded($extension)) {
                $this->output->warning(sprintf('        %s is not loaded — %s.', $extension, $consequence));
            }
        }

        return $ok;
    }

    /**
     * Confirm the runtime directories exist and can be written to.
     *
     * They are created during boot, so this is really a permissions check: a
     * directory that exists but is read-only fails later, at the first log
     * write, in a way that is much harder to interpret.
     */
    private function checkDirectories(): bool
    {
        $this->output->info('2/7  Writable directories');

        $required = ['storage', 'storage/logs', 'storage/temp', 'storage/cache', 'storage/exports', 'database/backups'];
        $ok = true;

        foreach ($required as $relative) {
            $path = $this->app->basePath($relative);

            if (!is_dir($path) && !mkdir($path, 0o750, true) && !is_dir($path)) {
                $this->output->error('        Could not create ' . $relative);
                $ok = false;

                continue;
            }

            if (!is_writable($path)) {
                $this->output->error('        Not writable: ' . $relative);
                $ok = false;
            }
        }

        if ($ok) {
            $this->output->comment('        All present and writable');
        }

        return $ok;
    }

    private function ensureEnvironmentFile(): bool
    {
        $this->output->info('3/7  Environment file');

        $envFile = $this->app->basePath('.env');

        if (is_file($envFile)) {
            $this->output->comment('        .env already exists; leaving it alone');

            return true;
        }

        $template = $this->app->basePath('.env.example');

        if (!is_file($template)) {
            $this->output->error('        Neither .env nor .env.example is present.');

            return false;
        }

        if (!copy($template, $envFile)) {
            $this->output->error('        .env could not be created from the template.');

            return false;
        }

        // The template is world-readable because it holds no secrets. The file
        // copied from it will hold the database password and the application
        // key, so it must not stay that way.
        if (DIRECTORY_SEPARATOR !== '\\') {
            @chmod($envFile, 0o600);
        }

        $this->output->comment('        .env created from .env.example');
        $this->output->warning('        Review the database settings in .env before continuing.');

        return true;
    }

    private function ensureApplicationKey(): bool
    {
        $this->output->info('4/7  Application key');

        if ((string) config('app.key', '') !== '') {
            $this->output->comment('        Already set');

            return true;
        }

        $generate = new KeyGenerateCommand($this->app, $this->output);
        $generate->setInput([], $this->options);

        if ($generate->handle() !== 0) {
            return false;
        }

        return true;
    }

    /**
     * Create the database if it is absent.
     *
     * Connects without naming a database, because naming one that does not
     * exist fails at connection time and the operator is left reading a driver
     * error rather than being told the obvious thing.
     */
    private function ensureDatabase(): bool
    {
        $this->output->info('5/7  Database');

        $name = (string) config('database.connections.mysql.database', '');

        if ($name === '') {
            $this->output->error('        No database name is configured. Set DB_DATABASE in .env.');

            return false;
        }

        try {
            if ($this->service(Connection::class)->isHealthy()) {
                $this->output->comment(sprintf('        "%s" is reachable', $name));

                return true;
            }
        } catch (Throwable) {
            // Falls through to creating it: an unreachable database is the
            // case this step exists for.
        }

        $host    = (string) config('database.connections.mysql.host', '127.0.0.1');
        $port    = (int) config('database.connections.mysql.port', 3306);
        $user    = (string) config('database.connections.mysql.username', 'root');
        $pass    = (string) config('database.connections.mysql.password', '');
        $charset = (string) config('database.connections.mysql.charset', 'utf8mb4');
        $collate = (string) config('database.connections.mysql.collation', 'utf8mb4_unicode_ci');

        try {
            $server = new PDO(
                sprintf('mysql:host=%s;port=%d;charset=%s', $host, $port, $charset),
                $user,
                $pass,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );

            // The name comes from configuration rather than from input, and is
            // additionally restricted here: an identifier cannot be bound as a
            // parameter, so nothing unexpected may reach this statement.
            if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
                $this->output->error(sprintf(
                    '        "%s" is not a usable database name. Use letters, digits and underscores.',
                    $name
                ));

                return false;
            }

            $server->exec(sprintf(
                'CREATE DATABASE IF NOT EXISTS `%s` CHARACTER SET %s COLLATE %s',
                $name,
                $charset,
                $collate
            ));
        } catch (PDOException $e) {
            $this->output->error('        Could not reach the database server: ' . $e->getMessage());
            $this->output->comment('        Check that MySQL is running and that DB_USERNAME and DB_PASSWORD in .env are right.');

            return false;
        }

        $this->output->comment(sprintf('        "%s" created', $name));

        return true;
    }

    private function migrate(): bool
    {
        $this->output->info('6/7  Schema');

        $runner = new MigrationRunner(
            $this->service(Connection::class),
            $this->app->basePath((string) config('database.migrations.path', 'database/migrations'))
        );

        try {
            $applied = $runner->migrate();
        } catch (Throwable $e) {
            $this->output->error('        Migration failed: ' . $e->getMessage());

            return false;
        }

        foreach ($runner->output() as $line) {
            $this->output->comment('        ' . $line);
        }

        $this->output->comment(sprintf('        %d migration(s) applied', count($applied)));

        return true;
    }

    private function seed(): bool
    {
        $this->output->info('7/7  Reference data');

        $seed = new SeedCommand($this->app, $this->output);
        $seed->setInput([], $this->options);

        return $seed->handle() === 0;
    }

    private function reportAssets(): void
    {
        /** @var array<string,array<string,string>> $libraries */
        $libraries = (array) config('assets.vendor', []);

        $missing = 0;

        foreach ($libraries as $library) {
            if (!is_file($this->app->basePath('public/' . $library['local']))) {
                $missing++;
            }
        }

        if ($missing === 0) {
            return;
        }

        $this->output->line();
        $this->output->warning(sprintf(
            '%d front-end librar(y/ies) are not present locally.',
            $missing
        ));
        $this->output->comment('Run php bin/console assets:fetch on a machine with internet access.');
        $this->output->comment('Until then the interface uses its built-in styling, which is plainer but works.');
    }

    private function closingNotes(): void
    {
        $this->output->line();
        $this->output->success('Installation complete.');
        $this->output->line();
        $this->output->line('What to do next:');
        $this->output->line('  1. Sign in with the administrator credentials printed above and change the password.');
        $this->output->line('  2. Register the gate stations: php bin/console device:register --gate=entry');
        $this->output->line('  3. Check the security posture: php bin/console security:audit');
        $this->output->line('  4. Schedule php bin/console maintenance:run every few minutes,');
        $this->output->line('     and php bin/console backup:create --scheduled nightly.');
        $this->output->line();

        if (!(bool) config('security.transport.force_https', true)) {
            $this->output->warning('HTTPS is not enforced. Do not run this on a real network until it is.');
        }
    }
}
