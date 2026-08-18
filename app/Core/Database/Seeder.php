<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Core\Console\Output;

/**
 * Base class for database seeders.
 *
 * Seeders are idempotent: every insert is an upsert keyed on the table's
 * natural unique column. Running `seed` twice must not duplicate a permission
 * or reset an administrator's password.
 *
 * @package App\Core\Database
 * @version 1.0.0
 */
abstract class Seeder
{
    protected int $inserted = 0;
    protected int $updated  = 0;
    protected int $skipped  = 0;

    public function __construct(
        protected readonly Connection $connection,
        protected readonly Output $output
    ) {
    }

    /**
     * Seed the data this seeder owns.
     */
    abstract public function run(): void;

    /**
     * Short description shown while the seeder runs.
     */
    abstract public function description(): string;

    /**
     * Insert a row, or update it when a row with the same natural key exists.
     *
     * @param array<string,mixed> $attributes Full row.
     * @param list<string>        $keyColumns Columns forming the natural key.
     * @param list<string>        $protect    Columns never overwritten on update.
     */
    protected function upsert(string $table, array $attributes, array $keyColumns, array $protect = []): int
    {
        $conditions = [];
        $bindings   = [];

        foreach ($keyColumns as $column) {
            $conditions[]        = sprintf('`%s` = :key_%s', $column, $column);
            $bindings['key_' . $column] = $attributes[$column] ?? null;
        }

        $existing = $this->connection->selectOne(
            sprintf('SELECT * FROM `%s` WHERE %s LIMIT 1', $table, implode(' AND ', $conditions)),
            $bindings
        );

        if ($existing === null) {
            $columns      = array_keys($attributes);
            $placeholders = array_map(static fn (string $c): string => ':' . $c, $columns);

            $id = $this->connection->insert(
                sprintf(
                    'INSERT INTO `%s` (%s) VALUES (%s)',
                    $table,
                    implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
                    implode(', ', $placeholders)
                ),
                $attributes
            );

            $this->inserted++;

            return $id;
        }

        // Refresh the descriptive columns while leaving anything the operator
        // may have tuned (and anything listed in $protect) alone.
        $updatable = array_diff(array_keys($attributes), $keyColumns, $protect);

        if ($updatable !== []) {
            $assignments = [];
            $updateData  = [];

            foreach ($updatable as $column) {
                $assignments[]       = sprintf('`%s` = :%s', $column, $column);
                $updateData[$column] = $attributes[$column];
            }

            $updateData += $bindings;

            $this->connection->execute(
                sprintf(
                    'UPDATE `%s` SET %s WHERE %s',
                    $table,
                    implode(', ', $assignments),
                    implode(' AND ', $conditions)
                ),
                $updateData
            );

            $this->updated++;
        } else {
            $this->skipped++;
        }

        $primaryKey = $this->primaryKeyColumn($table);

        return (int) ($existing[$primaryKey] ?? 0);
    }

    /**
     * Whether a table already holds rows, used to skip demonstration data.
     */
    protected function tableHasRows(string $table): bool
    {
        return (int) $this->connection->scalar(sprintf('SELECT COUNT(*) FROM `%s`', $table)) > 0;
    }

    /**
     * Look up a primary key by a natural key.
     */
    protected function idOf(string $table, string $column, mixed $value): ?int
    {
        $primaryKey = $this->primaryKeyColumn($table);

        $id = $this->connection->scalar(
            sprintf('SELECT `%s` FROM `%s` WHERE `%s` = ? LIMIT 1', $primaryKey, $table, $column),
            [$value]
        );

        return $id === null ? null : (int) $id;
    }

    /**
     * Determine a table's primary key column from the information schema.
     */
    protected function primaryKeyColumn(string $table): string
    {
        static $cache = [];

        if (isset($cache[$table])) {
            return $cache[$table];
        }

        $column = $this->connection->scalar(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_KEY = ?
              LIMIT 1',
            [$table, 'PRI']
        );

        return $cache[$table] = (string) ($column ?? 'id');
    }

    protected function now(): string
    {
        return now()->format('Y-m-d H:i:s');
    }

    /**
     * Summary line printed after the seeder runs.
     */
    public function summary(): string
    {
        return sprintf('%d inserted, %d updated, %d unchanged', $this->inserted, $this->updated, $this->skipped);
    }
}
