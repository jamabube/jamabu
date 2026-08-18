<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Connection;
use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;
use App\Exceptions\NotFoundException;
use InvalidArgumentException;

/**
 * Base data-access class.
 *
 * Repositories translate between the database and plain arrays. They contain
 * no business rules whatsoever: the decision about *whether* a vehicle may
 * enter belongs to a service, while the decision about *how* to read a vehicle
 * row belongs here.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
abstract class BaseRepository
{
    /** Physical table name. */
    protected string $table = '';

    /** Auto-increment primary key column. */
    protected string $primaryKey = 'id';

    /** Columns a caller is permitted to write. Anything else is discarded. */
    protected array $fillable = [];

    /** Columns that may be used for sorting, guarding the ORDER BY position. */
    protected array $sortable = [];

    /** Columns scanned by the module's free-text search. */
    protected array $searchable = [];

    /** Whether the table carries created_at / updated_at columns. */
    protected bool $timestamps = true;

    /** Soft-delete column, or null when rows are removed physically. */
    protected ?string $softDeleteColumn = 'deleted_at';

    public function __construct(protected readonly Connection $connection)
    {
        if ($this->table === '') {
            throw new InvalidArgumentException(static::class . ' must define a table name.');
        }
    }

    public function table(): string
    {
        return $this->table;
    }

    public function primaryKey(): string
    {
        return $this->primaryKey;
    }

    /**
     * Start a query against this repository's table.
     */
    public function query(): QueryBuilder
    {
        $query = (new QueryBuilder($this->connection))->table($this->table);

        if ($this->softDeleteColumn !== null) {
            $query->whereNull($this->table . '.' . $this->softDeleteColumn);
        }

        return $query;
    }

    /**
     * Start a query that includes soft-deleted rows. Used by audit views and
     * by uniqueness checks that must consider archived records.
     */
    public function queryWithTrashed(): QueryBuilder
    {
        return (new QueryBuilder($this->connection))->table($this->table);
    }

    /**
     * Fetch a single row by primary key.
     *
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        return $this->query()->whereEquals($this->table . '.' . $this->primaryKey, $id)->first();
    }

    /**
     * Fetch a row by primary key or raise a 404.
     *
     * @return array<string,mixed>
     *
     * @throws NotFoundException
     */
    public function findOrFail(int $id): array
    {
        $record = $this->find($id);

        if ($record === null) {
            throw NotFoundException::record($this->entityName(), $id);
        }

        return $record;
    }

    /**
     * Fetch the first row matching a column value.
     *
     * @return array<string,mixed>|null
     */
    public function findBy(string $column, mixed $value): ?array
    {
        return $this->query()->whereEquals($this->table . '.' . $column, $value)->first();
    }

    /**
     * Fetch every row matching a column value.
     *
     * @return list<array<string,mixed>>
     */
    public function findAllBy(string $column, mixed $value): array
    {
        return $this->query()->whereEquals($this->table . '.' . $column, $value)->get();
    }

    /**
     * Fetch every non-deleted row, optionally ordered.
     *
     * @return list<array<string,mixed>>
     */
    public function all(?string $orderBy = null, string $direction = 'ASC'): array
    {
        $query = $this->query();

        if ($orderBy !== null) {
            $query->orderBy($this->assertSortable($orderBy), $direction);
        }

        return $query->get();
    }

    /**
     * Insert a row and return its new primary key.
     *
     * @param array<string,mixed> $attributes
     */
    public function create(array $attributes): int
    {
        $data = $this->filterFillable($attributes);

        if ($this->timestamps) {
            $now = $this->timestamp();
            $data['created_at'] ??= $now;
            $data['updated_at'] ??= $now;
        }

        if ($data === []) {
            throw new InvalidArgumentException('No writable attributes were supplied.');
        }

        $columns      = array_keys($data);
        $placeholders = array_map(static fn (string $column): string => ':' . $column, $columns);

        $sql = sprintf(
            'INSERT INTO `%s` (%s) VALUES (%s)',
            $this->table,
            implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns)),
            implode(', ', $placeholders)
        );

        return $this->connection->insert($sql, $data);
    }

    /**
     * Update a row by primary key. Returns the number of affected rows.
     *
     * @param array<string,mixed> $attributes
     */
    public function update(int $id, array $attributes): int
    {
        $data = $this->filterFillable($attributes);

        if ($this->timestamps) {
            $data['updated_at'] = $this->timestamp();
        }

        if ($data === []) {
            return 0;
        }

        $assignments = [];
        foreach (array_keys($data) as $column) {
            $assignments[] = sprintf('`%s` = :%s', $column, $column);
        }

        $data['__pk'] = $id;

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `%s` = :__pk',
            $this->table,
            implode(', ', $assignments),
            $this->primaryKey
        );

        return $this->connection->execute($sql, $data);
    }

    /**
     * Update every row matching a column value.
     *
     * @param array<string,mixed> $attributes
     */
    public function updateWhere(string $column, mixed $value, array $attributes): int
    {
        $data = $this->filterFillable($attributes);

        if ($this->timestamps) {
            $data['updated_at'] = $this->timestamp();
        }

        if ($data === []) {
            return 0;
        }

        $assignments = [];
        foreach (array_keys($data) as $name) {
            $assignments[] = sprintf('`%s` = :%s', $name, $name);
        }

        $data['__match'] = $value;

        $sql = sprintf(
            'UPDATE `%s` SET %s WHERE `%s` = :__match',
            $this->table,
            implode(', ', $assignments),
            $this->assertColumn($column)
        );

        return $this->connection->execute($sql, $data);
    }

    /**
     * Soft-delete a row, or delete it physically when the table has no
     * soft-delete column.
     *
     * Historical records are preserved by default: the specification requires
     * that deleting a vehicle or a user never erases the monitoring history
     * that references it.
     */
    public function delete(int $id, ?int $deletedBy = null): int
    {
        if ($this->softDeleteColumn === null) {
            return $this->connection->execute(
                sprintf('DELETE FROM `%s` WHERE `%s` = :id', $this->table, $this->primaryKey),
                ['id' => $id]
            );
        }

        $bindings = ['id' => $id, 'deleted_at' => $this->timestamp()];
        $sets     = sprintf('`%s` = :deleted_at', $this->softDeleteColumn);

        if ($deletedBy !== null && $this->hasColumn('deleted_by')) {
            $sets .= ', `deleted_by` = :deleted_by';
            $bindings['deleted_by'] = $deletedBy;
        }

        if ($this->timestamps) {
            $sets .= ', `updated_at` = :updated_at';
            $bindings['updated_at'] = $this->timestamp();
        }

        return $this->connection->execute(
            sprintf('UPDATE `%s` SET %s WHERE `%s` = :id', $this->table, $sets, $this->primaryKey),
            $bindings
        );
    }

    /**
     * Reverse a soft delete.
     */
    public function restore(int $id): int
    {
        if ($this->softDeleteColumn === null) {
            return 0;
        }

        return $this->connection->execute(
            sprintf(
                'UPDATE `%s` SET `%s` = NULL WHERE `%s` = :id',
                $this->table,
                $this->softDeleteColumn,
                $this->primaryKey
            ),
            ['id' => $id]
        );
    }

    /**
     * Permanently remove a row. Reserved for administrative maintenance of
     * tables that carry no historical value.
     */
    public function forceDelete(int $id): int
    {
        return $this->connection->execute(
            sprintf('DELETE FROM `%s` WHERE `%s` = :id', $this->table, $this->primaryKey),
            ['id' => $id]
        );
    }

    /**
     * Whether any row holds $value in $column, optionally ignoring one row.
     *
     * Uniqueness is checked against soft-deleted rows too, because a plate
     * number or RFID UID must stay unique across the whole history.
     */
    public function existsWhere(string $column, mixed $value, ?int $exceptId = null): bool
    {
        $query = $this->queryWithTrashed()->whereEquals($this->assertColumn($column), $value);

        if ($exceptId !== null) {
            $query->where($this->primaryKey, '!=', $exceptId);
        }

        return $query->exists();
    }

    public function count(): int
    {
        return $this->query()->count();
    }

    /**
     * Count rows matching a column value.
     */
    public function countWhere(string $column, mixed $value): int
    {
        return $this->query()->whereEquals($this->assertColumn($column), $value)->count();
    }

    /**
     * Paginate a prepared query, applying a validated sort.
     *
     * @param array{page?:int,per_page?:int,sort?:string,direction?:string} $options
     */
    protected function paginateQuery(QueryBuilder $query, array $options): Paginator
    {
        $sort = (string) ($options['sort'] ?? '');
        if ($sort !== '') {
            $query->orderBy($this->assertSortable($sort), (string) ($options['direction'] ?? 'ASC'));
        }

        return Paginator::fromQuery(
            $query,
            max(1, (int) ($options['page'] ?? 1)),
            (int) ($options['per_page'] ?? config('app.pagination.default_per_page', 25))
        );
    }

    /**
     * Discard any attribute the repository does not declare fillable.
     *
     * This is mass-assignment protection: a crafted form field can never write
     * to a column the module did not intend to expose.
     *
     * @param array<string,mixed> $attributes
     *
     * @return array<string,mixed>
     */
    protected function filterFillable(array $attributes): array
    {
        if ($this->fillable === []) {
            return $attributes;
        }

        return array_intersect_key($attributes, array_flip($this->fillable));
    }

    /**
     * Reject a sort column that is not on the allow-list.
     *
     * @throws InvalidArgumentException
     */
    protected function assertSortable(string $column): string
    {
        // A qualified name ("vehicles.plate_number") is compared on its last segment.
        $bare = str_contains($column, '.') ? substr($column, (int) strrpos($column, '.') + 1) : $column;

        if ($this->sortable !== [] && !in_array($bare, $this->sortable, true)) {
            throw new InvalidArgumentException(sprintf('Sorting by "%s" is not permitted.', $column));
        }

        return $column;
    }

    /**
     * Reject a column name that is not a plain identifier.
     *
     * @throws InvalidArgumentException
     */
    protected function assertColumn(string $column): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?$/', $column) !== 1) {
            throw new InvalidArgumentException(sprintf('Unsafe column name "%s".', $column));
        }

        return $column;
    }

    /**
     * @return list<string>
     */
    public function searchableColumns(): array
    {
        return $this->searchable;
    }

    /**
     * Whether the table declares a column. Cached per request.
     */
    protected function hasColumn(string $column): bool
    {
        static $cache = [];

        $key = $this->table;
        if (!isset($cache[$key])) {
            $cache[$key] = $this->columnNames();
        }

        return in_array($column, $cache[$key], true);
    }

    /**
     * List the table's column names.
     *
     * @return list<string>
     */
    protected function columnNames(): array
    {
        if ($this->connection->driver() === 'sqlite') {
            /** @var list<array<string,mixed>> $rows */
            $rows = $this->connection->select(sprintf('PRAGMA table_info(`%s`)', $this->table));

            return array_map(static fn (array $row): string => (string) $row['name'], $rows);
        }

        /** @var list<mixed> $names */
        $names = $this->connection->column(
            'SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$this->table]
        );

        return array_map(strval(...), $names);
    }

    /**
     * Current timestamp in the storage format.
     */
    protected function timestamp(): string
    {
        return now()->format('Y-m-d H:i:s');
    }

    /**
     * Human-readable entity name used in "not found" messages.
     */
    protected function entityName(): string
    {
        $singular = rtrim(str_replace('_', ' ', $this->table), 's');

        return ucfirst($singular);
    }

    public function connection(): Connection
    {
        return $this->connection;
    }
}
