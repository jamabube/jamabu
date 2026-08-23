<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Database\Connection;
use App\Core\Database\MigrationRunner;
use Tests\TestCase;

/**
 * Verifies that a half-built schema is recognised as one.
 *
 * MySQL commits DDL as it goes, so a migration that fails partway leaves its
 * tables behind and records nothing as applied. Re-running then collides on
 * "already exists" partway through a file, which reads like a fault in the
 * migration rather than the residue of the run before it. The runner has to
 * tell that state apart from a fresh database and from a healthy one, because
 * the remedy for it — dropping every table — must never be offered for either
 * of the others.
 *
 * The checks run against a scratch database, created and dropped here, so the
 * suite's own schema is never at risk.
 *
 * @package Tests\Integration
 * @version 1.0.0
 */
final class MigrationStateTest extends TestCase
{
    protected bool $requiresDatabase = true;

    private Connection $connection;

    /** Scratch schema, named so it cannot collide with a real one. */
    private string $scratch = 'vams_migration_state_probe';

    public function description(): string
    {
        return 'Telling a half-applied schema apart from a fresh or healthy one';
    }

    /**
     * These checks need a scratch database, and creating one is a privilege
     * the application account is deliberately not granted in a real
     * deployment. Where it is absent the suite is skipped rather than failed:
     * the account being unable to create databases is correct, not a defect.
     */
    public function canRun(): bool
    {
        if (!parent::canRun()) {
            return false;
        }

        $connection = $this->app->make(Connection::class);

        try {
            $connection->unprepared(sprintf('CREATE DATABASE IF NOT EXISTS `%s`', $this->scratch));
            $connection->unprepared(sprintf('DROP DATABASE IF EXISTS `%s`', $this->scratch));
        } catch (\Throwable) {
            return $this->skip('the database account may not create databases');
        }

        return true;
    }

    public function setUp(): void
    {
        $this->connection = $this->app->make(Connection::class);

        $this->connection->unprepared(sprintf('DROP DATABASE IF EXISTS `%s`', $this->scratch));
        $this->connection->unprepared(sprintf(
            'CREATE DATABASE `%s` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
            $this->scratch
        ));
    }

    public function tearDown(): void
    {
        $this->connection->unprepared(sprintf('DROP DATABASE IF EXISTS `%s`', $this->scratch));
    }

    /**
     * A runner pointed at the scratch database.
     *
     * The connection is switched with USE rather than by building a second
     * one, so the test cannot be affected by credentials differing between
     * this environment and another.
     */
    private function runnerOnScratch(): MigrationRunner
    {
        $this->connection->unprepared(sprintf('USE `%s`', $this->scratch));

        return new MigrationRunner(
            $this->connection,
            $this->app->basePath((string) config('database.migrations.path', 'database/migrations'))
        );
    }

    private function restoreConnection(): void
    {
        $this->connection->unprepared(sprintf('USE `%s`', $this->connection->databaseName()));
    }

    public function testAnEmptyDatabaseIsNotPartiallyMigrated(): void
    {
        $runner = $this->runnerOnScratch();
        $partial = $runner->isPartiallyMigrated();
        $this->restoreConnection();

        $this->assertFalse($partial, 'a fresh database is ready to migrate, not half-built');
    }

    public function testTablesWithNoRecordAreRecognised(): void
    {
        $runner = $this->runnerOnScratch();

        // Exactly what a migration that died after its first CREATE leaves.
        $this->connection->unprepared('CREATE TABLE `departments` (`department_id` INT UNSIGNED NOT NULL PRIMARY KEY)');

        $partial = $runner->isPartiallyMigrated();
        $this->restoreConnection();

        $this->assertTrue($partial, 'a table nothing accounts for is a half-applied migration');
    }

    public function testAFullyMigratedDatabaseIsNotPartial(): void
    {
        $runner = $this->runnerOnScratch();
        $runner->migrate();

        $partial = $runner->isPartiallyMigrated();
        $this->restoreConnection();

        $this->assertFalse($partial, 'a database with a recorded history is not half-built');
    }

    /**
     * The migrations table alone must not count.
     *
     * It is created by the runner before anything else, so counting it would
     * make every fresh database look half-built and offer to drop it.
     */
    public function testTheMigrationsTableAloneIsNotSchema(): void
    {
        $runner = $this->runnerOnScratch();

        // isPartiallyMigrated creates the table as its first act; calling it
        // twice proves the table it just made does not trip the second call.
        $runner->isPartiallyMigrated();
        $partial = $runner->isPartiallyMigrated();

        $this->restoreConnection();

        $this->assertFalse($partial, 'the bookkeeping table is not evidence of a partial migration');
    }

    public function testFreshRebuildsOverAHalfBuiltSchema(): void
    {
        $runner = $this->runnerOnScratch();

        // A table that collides with the first migration, and one that does
        // not appear in any migration at all: fresh has to clear both.
        $this->connection->unprepared('CREATE TABLE `reference_codes` (`reference_code_id` INT UNSIGNED NOT NULL PRIMARY KEY)');
        $this->connection->unprepared('CREATE TABLE `left_over` (`id` INT UNSIGNED NOT NULL PRIMARY KEY)');

        $runner->fresh();

        $partial = $runner->isPartiallyMigrated();

        $strays = (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
              WHERE `TABLE_SCHEMA` = DATABASE() AND `TABLE_NAME` = 'left_over'"
        );

        $applied = count($runner->status());

        $this->restoreConnection();

        $this->assertFalse($partial, 'the rebuilt schema is no longer half-built');
        $this->assertSame(0, $strays, 'a table belonging to no migration is dropped too');
        $this->assertGreaterThan(0, $applied, 'the migrations were applied over the cleared database');
    }
}
