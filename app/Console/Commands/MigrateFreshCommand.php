<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Database\Connection;
use App\Core\Database\MigrationRunner;

/**
 * Drop every table and migrate from scratch.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class MigrateFreshCommand extends Command
{
    protected string $name = 'migrate:fresh';
    protected string $description = 'Roll everything back and migrate again. DESTROYS ALL DATA.';
    protected string $usage = 'php bin/console migrate:fresh [--seed] [--force]';

    public function handle(): int
    {
        $this->output->title('Rebuild database schema');

        // A production database must never be rebuilt by accident.
        if ($this->app->isProduction() && !$this->isForced()) {
            $this->output->error('Refusing to rebuild a production database. Pass --force if this is genuinely intended.');

            return 1;
        }

        $this->output->warning(sprintf(
            'This will DROP every table in "%s" and destroy all data it holds.',
            $this->service(Connection::class)->databaseName()
        ));

        if (!$this->isForced() && !$this->output->confirm('Are you absolutely sure?')) {
            $this->output->info('Cancelled.');

            return 0;
        }

        $runner = new MigrationRunner(
            $this->service(Connection::class),
            $this->app->basePath((string) config('database.migrations.path', 'database/migrations'))
        );

        $runner->fresh();

        foreach ($runner->output() as $line) {
            $this->output->info($line);
        }

        $this->output->success('Schema rebuilt.');

        if ($this->hasOption('seed')) {
            $this->output->line();

            $seed = new SeedCommand($this->app, $this->output);
            $seed->setInput([], $this->options);

            return $seed->handle();
        }

        return 0;
    }
}
