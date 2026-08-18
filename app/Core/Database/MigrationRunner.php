<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Exceptions\DatabaseException;
use RuntimeException;
use Throwable;

/**
 * Schema migration runner.
 *
 * Migrations are plain SQL files containing an "@UP" section and a matching
 * "@DOWN" section, applied in filename order and recorded in
 * schema_migrations. A checksum is stored with each applied migration so that
 * editing a file after it has been applied is detected rather than silently
 * leaving two deployments with different schemas.
 *
 * @package App\Core\Database
 * @version 1.0.0
 */
class MigrationRunner
{
    private string $table;

    /** @var list<string> Messages describing what the last run did. */
    private array $output = [];

    public function __construct(
        private readonly Connection $connection,
        private readonly string $migrationPath
    ) {
        $this->table = (string) config('database.migrations.table', 'schema_migrations');
    }

    /**
     * Apply every migration that has not yet run.
     *
     * @return list<string> Names of the migrations applied.
     */
    public function migrate(): array
    {
        $this->ensureMigrationTable();

        $applied  = $this->appliedMigrations();
        $pending  = array_diff($this->availableMigrations(), array_keys($applied));
        $batch    = $this->nextBatch();
        $executed = [];

        $this->warnAboutModifiedMigrations($applied);

        foreach ($pending as $migration) {
            $this->applyOne($migration, $batch);
            $executed[] = $migration;
        }

        if ($executed === []) {
            $this->output[] = 'Nothing to migrate; the schema is up to date.';
        }

        return $executed;
    }

    /**
     * Roll back the most recent batch, or a given number of migrations.
     *
     * @return list<string> Names of the migrations rolled back.
     */
    public function rollback(?int $steps = null): array
    {
        $this->ensureMigrationTable();

        $rows = $steps === null
            ? $this->connection->select(
                sprintf('SELECT `migration` FROM `%s` WHERE `batch` = (SELECT MAX(`batch`) FROM `%s`) ORDER BY `migration` DESC', $this->table, $this->table)
            )
            : $this->connection->select(
                sprintf('SELECT `migration` FROM `%s` ORDER BY `migration` DESC LIMIT %d', $this->table, max(1, $steps))
            );

        $reverted = [];

        foreach ($rows as $row) {
            $migration = (string) $row['migration'];
            $this->revertOne($migration);
            $reverted[] = $migration;
        }

        if ($reverted === []) {
            $this->output[] = 'Nothing to roll back.';
        }

        return $reverted;
    }

    /**
     * Roll everything back and migrate again. Destroys all data, so the
     * calling command must confirm with the operator first.
     */
    public function fresh(): array
    {
        $this->ensureMigrationTable();

        // Roll back in reverse order, ignoring migrations that were never
        // applied so a partially-migrated database can still be reset.
        foreach (array_reverse($this->availableMigrations()) as $migration) {
            if ($this->isApplied($migration)) {
                $this->revertOne($migration);
            }
        }

        return $this->migrate();
    }

    /**
     * Report the state of every migration.
     *
     * @return list<array{migration:string,applied:bool,batch:int|null,applied_at:string|null,modified:bool}>
     */
    public function status(): array
    {
        $this->ensureMigrationTable();

        $applied = $this->appliedMigrations();
        $status  = [];

        foreach ($this->availableMigrations() as $migration) {
            $record = $applied[$migration] ?? null;

            $status[] = [
                'migration'  => $migration,
                'applied'    => $record !== null,
                'batch'      => $record === null ? null : (int) $record['batch'],
                'applied_at' => $record === null ? null : (string) $record['applied_at'],
                'modified'   => $record !== null && (string) $record['checksum'] !== $this->checksum($migration),
            ];
        }

        // A migration recorded in the database but missing from disk means a
        // file was deleted after being applied; surface it rather than hide it.
        foreach (array_diff(array_keys($applied), $this->availableMigrations()) as $orphan) {
            $status[] = [
                'migration'  => $orphan . ' (file missing)',
                'applied'    => true,
                'batch'      => (int) $applied[$orphan]['batch'],
                'applied_at' => (string) $applied[$orphan]['applied_at'],
                'modified'   => true,
            ];
        }

        return $status;
    }

    /**
     * Execute one migration's @UP section inside a transaction where possible.
     *
     * MySQL performs an implicit commit on DDL, so a failed migration cannot
     * be rolled back automatically. The runner therefore stops on the first
     * failure and reports exactly which statement failed, so the operator can
     * repair the schema deliberately rather than guess.
     */
    private function applyOne(string $migration, int $batch): void
    {
        $sql        = SqlScript::section($this->contents($migration), 'UP');
        $statements = SqlScript::split($sql);
        $startedAt  = microtime(true);

        foreach ($statements as $position => $statement) {
            try {
                $this->connection->unprepared($statement);
            } catch (Throwable $e) {
                throw new RuntimeException(sprintf(
                    "Migration \"%s\" failed at statement %d of %d.\n%s\n\nStatement:\n%s",
                    $migration,
                    $position + 1,
                    count($statements),
                    self::reason($e),
                    self::excerpt($statement)
                ), 0, $e);
            }
        }

        $duration = (int) round((microtime(true) - $startedAt) * 1000);

        $this->connection->execute(
            sprintf(
                'INSERT INTO `%s` (`migration`, `batch`, `checksum`, `duration_ms`) VALUES (:migration, :batch, :checksum, :duration)',
                $this->table
            ),
            [
                'migration' => $migration,
                'batch'     => $batch,
                'checksum'  => $this->checksum($migration),
                'duration'  => $duration,
            ]
        );

        $this->output[] = sprintf(
            'Applied %s (%d statement%s, %dms).',
            $migration,
            count($statements),
            count($statements) === 1 ? '' : 's',
            $duration
        );
    }

    /**
     * Execute one migration's @DOWN section and forget it.
     */
    private function revertOne(string $migration): void
    {
        $sql = SqlScript::section($this->contents($migration), 'DOWN');

        if (trim($sql) === '') {
            throw new RuntimeException(sprintf(
                'Migration "%s" has no @DOWN section and cannot be rolled back.',
                $migration
            ));
        }

        foreach (SqlScript::split($sql) as $position => $statement) {
            try {
                $this->connection->unprepared($statement);
            } catch (Throwable $e) {
                // A teardown that trips over an object which is already gone
                // should keep going. Without this, a database left in a partial
                // state by an interrupted rollback can never be cleaned up by
                // the tool that is supposed to clean it up.
                if (self::isMissingObjectError($e)) {
                    $this->output[] = sprintf(
                        'Skipped a teardown statement in %s: the object it drops is already absent.',
                        $migration
                    );

                    continue;
                }

                throw new RuntimeException(sprintf(
                    "Rollback of \"%s\" failed at statement %d.\n%s\n\nStatement:\n%s",
                    $migration,
                    $position + 1,
                    self::reason($e),
                    self::excerpt($statement)
                ), 0, $e);
            }
        }

        $this->connection->execute(
            sprintf('DELETE FROM `%s` WHERE `migration` = :migration', $this->table),
            ['migration' => $migration]
        );

        $this->output[] = sprintf('Rolled back %s.', $migration);
    }

    /**
     * Create the bookkeeping table when it does not exist yet.
     */
    private function ensureMigrationTable(): void
    {
        $this->connection->unprepared(sprintf(
            'CREATE TABLE IF NOT EXISTS `%s` (
                `migration_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `migration`    VARCHAR(180) NOT NULL,
                `batch`        INT UNSIGNED NOT NULL DEFAULT 1,
                `checksum`     CHAR(64)     NOT NULL,
                `duration_ms`  INT UNSIGNED NOT NULL DEFAULT 0,
                `applied_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`migration_id`),
                UNIQUE KEY `uq_%s_name` (`migration`),
                KEY `idx_%s_batch` (`batch`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
            $this->table,
            $this->table,
            $this->table
        ));
    }

    /**
     * Migration filenames on disk, in application order.
     *
     * @return list<string>
     */
    public function availableMigrations(): array
    {
        $files = glob(rtrim($this->migrationPath, '/\\') . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        $names = array_map(static fn (string $path): string => basename($path), $files);

        sort($names, SORT_NATURAL);

        return array_values($names);
    }

    /**
     * @return array<string,array<string,mixed>> migration name => row
     */
    private function appliedMigrations(): array
    {
        $rows   = $this->connection->select(sprintf('SELECT * FROM `%s`', $this->table));
        $keyed  = [];

        foreach ($rows as $row) {
            $keyed[(string) $row['migration']] = $row;
        }

        return $keyed;
    }

    private function isApplied(string $migration): bool
    {
        return (int) $this->connection->scalar(
            sprintf('SELECT COUNT(*) FROM `%s` WHERE `migration` = ?', $this->table),
            [$migration]
        ) > 0;
    }

    private function nextBatch(): int
    {
        return (int) $this->connection->scalar(sprintf('SELECT COALESCE(MAX(`batch`), 0) + 1 FROM `%s`', $this->table));
    }

    /**
     * Report any applied migration whose file no longer matches its checksum.
     *
     * @param array<string,array<string,mixed>> $applied
     */
    private function warnAboutModifiedMigrations(array $applied): void
    {
        foreach ($applied as $migration => $record) {
            if (!in_array($migration, $this->availableMigrations(), true)) {
                continue;
            }

            if ((string) $record['checksum'] !== $this->checksum($migration)) {
                $this->output[] = sprintf(
                    'WARNING: "%s" has been modified since it was applied. Deployments may have diverged.',
                    $migration
                );
            }
        }
    }

    private function contents(string $migration): string
    {
        $path = rtrim($this->migrationPath, '/\\') . DIRECTORY_SEPARATOR . $migration;

        if (!is_readable($path)) {
            throw new RuntimeException(sprintf('Migration file "%s" cannot be read.', $path));
        }

        return (string) file_get_contents($path);
    }

    private function checksum(string $migration): string
    {
        // Line endings are normalised so a file checked out on Windows and on
        // Linux produces the same checksum.
        return hash('sha256', str_replace("\r\n", "\n", $this->contents($migration)));
    }

    /**
     * Whether a failure means "the thing you asked me to drop is not there".
     *
     * Only these specific server codes are tolerated during a teardown; a
     * genuine failure such as a foreign key still in use (1451) or a syntax
     * error still aborts the rollback.
     */
    private static function isMissingObjectError(Throwable $e): bool
    {
        $message = self::reason($e);

        $codes = [
            '1091', // Can't DROP; check that the column/key exists
            '1051', // Unknown table
            '1146', // Table doesn't exist
            '1176', // Key does not exist
            '1025', // Error on rename (raised for some foreign-key drops)
            '1360', // Trigger does not exist
        ];

        foreach ($codes as $code) {
            if (str_contains($message, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The most useful description of a failure.
     *
     * DatabaseException deliberately keeps the driver message out of its public
     * message so it can never reach an HTTP client. On the console there is no
     * such risk and the driver message is exactly what the operator needs, so
     * it is unwrapped here.
     */
    private static function reason(Throwable $e): string
    {
        if ($e instanceof DatabaseException) {
            $driverMessage = $e->context()['driver_message'] ?? null;

            if (is_string($driverMessage) && $driverMessage !== '') {
                return $driverMessage;
            }
        }

        return $e->getMessage();
    }

    /**
     * Shorten a statement for an error message.
     */
    private static function excerpt(string $statement, int $limit = 400): string
    {
        $statement = trim($statement);

        return strlen($statement) <= $limit
            ? $statement
            : substr($statement, 0, $limit) . "\n  ... (" . (strlen($statement) - $limit) . ' more characters)';
    }

    /**
     * @return list<string>
     */
    public function output(): array
    {
        return $this->output;
    }

    /**
     * Whether the schema appears to be installed.
     */
    public function isInstalled(): bool
    {
        try {
            return (int) $this->connection->scalar(sprintf('SELECT COUNT(*) FROM `%s`', $this->table)) > 0;
        } catch (DatabaseException) {
            return false;
        }
    }
}
