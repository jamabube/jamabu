<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use Tests\Support\TestRunner;

/**
 * Run the automated test suite.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class TestCommand extends Command
{
    protected string $name = 'test';
    protected string $description = 'Run the unit and integration test suites.';
    protected string $usage = 'php bin/console test [--filter=Name]';

    public function handle(): int
    {
        $this->output->title(sprintf('%s test suite', (string) config('app.short_name', 'VAMS')));

        $filter = $this->option('filter');

        return (new TestRunner($this->app, $this->output))->run(is_string($filter) ? $filter : null);
    }
}
