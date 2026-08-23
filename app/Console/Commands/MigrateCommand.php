<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Database\Connection;
use App\Core\Database\MigrationRunner;

/**
 * Apply pending schema migrations.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class MigrateCommand extends Command
{
    protected string $name = 'migrate';
    protected string $description = 'Apply every pending database migration.';
    protected string $usage = 'php bin/console migrate [--seed] [--no-remedy]';

    /**
     * Exit code for a database holding schema no migration accounts for.
     *
     * Distinct from a general failure so a caller — start.bat in particular —
     * can offer the rebuild rather than printing instructions and stopping.
     */
    public const EXIT_PARTIALLY_MIGRATED = 3;

    public function handle(): int
    {
        $runner = $this->runner();

        $this->output->title('Database migration');

        // Checked before anything is applied. Left to run, the first migration
        // collides partway through its own statements, which reads like a
        // fault in the migration rather than a database that was already
        // half-built.
        if ($runner->isPartiallyMigrated()) {
            foreach ($runner->partialMigrationExplanation() as $line) {
                $this->output->warning($line);
            }

            $this->output->line();
            $this->output->error('The schema has to be rebuilt before it can be migrated.');

            // A caller that is about to offer the rebuild itself passes
            // --no-remedy. Printing "type this command" immediately above a
            // prompt offering to run it reads as two different instructions
            // for the same problem.
            if (!$this->hasOption('no-remedy')) {
                $this->output->line();
                $this->output->line('    php bin/console migrate:fresh --seed');
                $this->output->line();
                $this->output->comment('That drops every table in the database and destroys the data in them.');
                $this->output->comment('On a fresh installation there is nothing to lose; on a running one,');
                $this->output->comment('take a backup first with: php bin/console backup:create');
            }

            return self::EXIT_PARTIALLY_MIGRATED;
        }

        $applied = $runner->migrate();

        foreach ($runner->output() as $line) {
            str_starts_with($line, 'WARNING')
                ? $this->output->warning(substr($line, 9))
                : $this->output->info($line);
        }

        if ($applied !== []) {
            $this->output->success(sprintf('%d migration(s) applied.', count($applied)));
        }

        if ($this->hasOption('seed')) {
            $this->output->line();

            $seed = new SeedCommand($this->app, $this->output);
            $seed->setInput([], $this->options);

            return $seed->handle();
        }

        return 0;
    }

    private function runner(): MigrationRunner
    {
        return new MigrationRunner(
            $this->service(Connection::class),
            $this->app->basePath((string) config('database.migrations.path', 'database/migrations'))
        );
    }
}
