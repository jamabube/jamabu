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
    protected string $usage = 'php bin/console migrate [--seed]';

    public function handle(): int
    {
        $runner = $this->runner();

        $this->output->title('Database migration');

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
