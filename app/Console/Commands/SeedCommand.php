<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Database\Connection;
use App\Core\Database\Seeder;
use Throwable;

/**
 * Populate the database with reference data and the first administrator.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class SeedCommand extends Command
{
    protected string $name = 'seed';
    protected string $description = 'Seed reference data, roles, permissions, settings and the administrator account.';
    protected string $usage = 'php bin/console seed [--demo] [--only=ClassName]';

    /**
     * Seeders in dependency order.
     *
     * @var list<class-string<Seeder>>
     */
    private const SEEDERS = [
        \Database\Seeders\ReferenceSeeder::class,
        \Database\Seeders\RbacSeeder::class,
        \Database\Seeders\SettingsSeeder::class,
        \Database\Seeders\AdministratorSeeder::class,
    ];

    /**
     * Seeders that only run when --demo is passed.
     *
     * @var list<class-string<Seeder>>
     */
    private const DEMO_SEEDERS = [
        \Database\Seeders\DemonstrationDataSeeder::class,
    ];

    public function handle(): int
    {
        $connection = $this->service(Connection::class);

        $seeders = self::SEEDERS;

        if ($this->hasOption('demo')) {
            if ($this->app->isProduction() && !$this->isForced()) {
                $this->output->error('Refusing to load demonstration data into a production database. Pass --force to override.');

                return 1;
            }

            $seeders = array_merge($seeders, self::DEMO_SEEDERS);
        }

        $only = $this->option('only');
        if (is_string($only) && $only !== '') {
            $seeders = array_values(array_filter(
                array_merge(self::SEEDERS, self::DEMO_SEEDERS),
                static fn (string $class): bool => str_ends_with($class, '\\' . $only) || $class === $only
            ));

            if ($seeders === []) {
                $this->output->error(sprintf('No seeder matches "%s".', $only));

                return 1;
            }
        }

        $this->output->title('Database seeding');

        foreach ($seeders as $class) {
            /** @var Seeder $seeder */
            $seeder = new $class($connection, $this->output);

            $this->output->info(sprintf('%s — %s', $this->shortName($class), $seeder->description()));

            // Each seeder runs in its own transaction: one failing seeder must
            // not leave a half-populated reference table behind.
            try {
                $connection->transaction(static function () use ($seeder): void {
                    $seeder->run();
                });
            } catch (Throwable $e) {
                $this->output->error(sprintf('%s failed.', $this->shortName($class)));
                $this->reportFailure($e);

                return 1;
            }

            $this->output->comment('        ' . $seeder->summary());
        }

        $this->output->success('Seeding complete.');

        return 0;
    }

    private function shortName(string $class): string
    {
        $position = strrpos($class, '\\');

        return $position === false ? $class : substr($class, $position + 1);
    }
}
