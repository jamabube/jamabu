<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Database\Connection;
use App\Core\Database\MigrationRunner;

/**
 * Roll back the most recent migration batch.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class MigrateRollbackCommand extends Command
{
    protected string $name = 'migrate:rollback';
    protected string $description = 'Roll back the last migration batch, or --steps=N migrations.';
    protected string $usage = 'php bin/console migrate:rollback [--steps=N] [--force]';

    public function handle(): int
    {
        $steps = $this->hasOption('steps') ? $this->optionInt('steps', 1) : null;

        $this->output->title('Migration rollback');
        $this->output->warning('Rolling back drops tables and destroys the data they hold.');

        if (!$this->isForced() && !$this->output->confirm('Continue?')) {
            $this->output->info('Cancelled.');

            return 0;
        }

        $runner = new MigrationRunner(
            $this->service(Connection::class),
            $this->app->basePath((string) config('database.migrations.path', 'database/migrations'))
        );

        $reverted = $runner->rollback($steps);

        foreach ($runner->output() as $line) {
            $this->output->info($line);
        }

        if ($reverted !== []) {
            $this->output->success(sprintf('%d migration(s) rolled back.', count($reverted)));
        }

        return 0;
    }
}
