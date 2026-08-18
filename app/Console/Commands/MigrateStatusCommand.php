<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Database\Connection;
use App\Core\Database\MigrationRunner;

/**
 * Show which migrations have been applied.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class MigrateStatusCommand extends Command
{
    protected string $name = 'migrate:status';
    protected string $description = 'List every migration and whether it has been applied.';

    public function handle(): int
    {
        $runner = new MigrationRunner(
            $this->service(Connection::class),
            $this->app->basePath((string) config('database.migrations.path', 'database/migrations'))
        );

        $rows     = [];
        $pending  = 0;
        $modified = 0;

        foreach ($runner->status() as $entry) {
            if (!$entry['applied']) {
                $pending++;
            }
            if ($entry['modified']) {
                $modified++;
            }

            $rows[] = [
                $entry['migration'],
                $entry['applied']
                    ? $this->output->colour('applied', 'green')
                    : $this->output->colour('pending', 'yellow'),
                $entry['batch'] === null ? '—' : (string) $entry['batch'],
                $entry['applied_at'] ?? '—',
                $entry['modified'] ? $this->output->colour('CHANGED', 'red') : '',
            ];
        }

        $this->output->title('Migration status');
        $this->output->table(['Migration', 'State', 'Batch', 'Applied at', 'Integrity'], $rows);

        if ($modified > 0) {
            $this->output->warning(sprintf(
                '%d applied migration(s) no longer match their recorded checksum.',
                $modified
            ));
        }

        $this->output->info(sprintf('%d pending migration(s).', $pending));

        return 0;
    }
}
