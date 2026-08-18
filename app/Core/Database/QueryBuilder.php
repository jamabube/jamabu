<?php

declare(strict_types=1);

namespace App\Core\Database;

use InvalidArgumentException;

/**
 * Fluent SELECT builder.
 *
 * Two properties matter here. First, every value becomes a bound parameter —
 * there is no code path that concatenates a value into SQL. Second, every
 * *identifier* (table, column, direction) is validated against a strict
 * pattern before it is quoted, because sort and filter columns often originate
 * in a query string and a bound parameter cannot protect an identifier
 * position.
 *
 * @package App\Core\Database
 * @version 1.0.0
 */
class QueryBuilder
{
    /** @var list<string> */
    private array $columns = ['*'];

    private string $table = '';
    private ?string $tableAlias = null;

    /** @var list<string> */
    private array $joins = [];

    /** @var list<string> */
    private array $wheres = [];

    /** @var list<string> */
    private array $havings = [];

    /** @var list<string> */
    private array $groups = [];

    /** @var list<string> */
    private array $orders = [];

    /** @var array<string,mixed> */
    private array $bindings = [];

    private ?int $limit = null;
    private ?int $offset = null;
    private bool $distinct = false;
    private int $bindingSequence = 0;

    /** Identifier pattern: letters, digits and underscore, optionally dotted. */
    private const IDENTIFIER = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_*]*)?$/';

    /** Comparison operators the builder will emit. */
    private const OPERATORS = ['=', '!=', '<>', '<', '<=', '>', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'IS', 'IS NOT'];

    public function __construct(private readonly Connection $connection)
    {
    }

    public function table(string $table, ?string $alias = null): self
    {
        $this->table      = $this->assertIdentifier($table);
        $this->tableAlias = $alias === null ? null : $this->assertIdentifier($alias);

        return $this;
    }

    /**
     * @param list<string> $columns Raw column expressions; see selectRaw() for
     *                              aggregates. Each must be a valid identifier
     *                              or "identifier AS alias".
     */
    public function select(array $columns): self
    {
        $this->columns = array_map([$this, 'compileColumn'], $columns);

        return $this;
    }

    /**
     * Add a trusted SQL expression to the select list.
     *
     * The caller is responsible for the expression's safety; it must never be
     * built from user input. Used for aggregates such as COUNT(*) and
     * TIMESTAMPDIFF(...).
     */
    public function selectRaw(string $expression): self
    {
        if ($this->columns === ['*']) {
            $this->columns = [];
        }

        $this->columns[] = $expression;

        return $this;
    }

    public function distinct(bool $distinct = true): self
    {
        $this->distinct = $distinct;

        return $this;
    }

    /**
     * Add a JOIN clause. The ON condition joins two identifiers only, never a
     * value, so it cannot carry user input.
     */
    public function join(string $table, string $first, string $second, string $type = 'INNER', ?string $alias = null): self
    {
        $type = strtoupper($type);
        if (!in_array($type, ['INNER', 'LEFT', 'RIGHT'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported join type "%s".', $type));
        }

        $target = $this->quote($this->assertIdentifier($table));
        if ($alias !== null) {
            $target .= ' AS ' . $this->quote($this->assertIdentifier($alias));
        }

        $this->joins[] = sprintf(
            '%s JOIN %s ON %s = %s',
            $type,
            $target,
            $this->quote($this->assertIdentifier($first)),
            $this->quote($this->assertIdentifier($second))
        );

        return $this;
    }

    public function leftJoin(string $table, string $first, string $second, ?string $alias = null): self
    {
        return $this->join($table, $first, $second, 'LEFT', $alias);
    }

    /**
     * Add a bound WHERE condition.
     */
    public function where(string $column, string $operator, mixed $value): self
    {
        $operator = strtoupper($operator);
        if (!in_array($operator, self::OPERATORS, true)) {
            throw new InvalidArgumentException(sprintf('Unsupported operator "%s".', $operator));
        }

        $placeholder     = $this->bind($value);
        $this->wheres[]  = sprintf('%s %s :%s', $this->quote($this->assertIdentifier($column)), $operator, $placeholder);

        return $this;
    }

    public function whereEquals(string $column, mixed $value): self
    {
        return $this->where($column, '=', $value);
    }

    public function whereNull(string $column): self
    {
        $this->wheres[] = $this->quote($this->assertIdentifier($column)) . ' IS NULL';

        return $this;
    }

    public function whereNotNull(string $column): self
    {
        $this->wheres[] = $this->quote($this->assertIdentifier($column)) . ' IS NOT NULL';

        return $this;
    }

    /**
     * @param list<mixed> $values
     */
    public function whereIn(string $column, array $values, bool $negate = false): self
    {
        if ($values === []) {
            // An empty IN list must match nothing rather than raise a syntax error.
            $this->wheres[] = $negate ? '1 = 1' : '1 = 0';

            return $this;
        }

        $placeholders = [];
        foreach ($values as $value) {
            $placeholders[] = ':' . $this->bind($value);
        }

        $this->wheres[] = sprintf(
            '%s %s (%s)',
            $this->quote($this->assertIdentifier($column)),
            $negate ? 'NOT IN' : 'IN',
            implode(', ', $placeholders)
        );

        return $this;
    }

    public function whereBetween(string $column, mixed $from, mixed $to): self
    {
        $this->wheres[] = sprintf(
            '%s BETWEEN :%s AND :%s',
            $this->quote($this->assertIdentifier($column)),
            $this->bind($from),
            $this->bind($to)
        );

        return $this;
    }

    /**
     * Case-insensitive partial match. The wildcards are added here so a caller
     * cannot smuggle its own pattern semantics.
     */
    public function whereLike(string $column, string $value): self
    {
        return $this->where($column, 'LIKE', '%' . $this->escapeLike($value) . '%');
    }

    /**
     * OR-combined LIKE across several columns. Used by the global search bar.
     *
     * @param list<string> $columns
     */
    public function whereAnyLike(array $columns, string $value): self
    {
        if ($columns === [] || $value === '') {
            return $this;
        }

        $pattern     = '%' . $this->escapeLike($value) . '%';
        $placeholder = $this->bind($pattern);
        $conditions  = [];

        foreach ($columns as $column) {
            $conditions[] = sprintf('%s LIKE :%s', $this->quote($this->assertIdentifier($column)), $placeholder);
        }

        $this->wheres[] = '(' . implode(' OR ', $conditions) . ')';

        return $this;
    }

    /**
     * Add a trusted SQL fragment with explicitly bound parameters.
     *
     * @param array<string,mixed> $bindings
     */
    public function whereRaw(string $expression, array $bindings = []): self
    {
        foreach ($bindings as $name => $value) {
            $this->bindings[ltrim($name, ':')] = $value;
        }

        $this->wheres[] = '(' . $expression . ')';

        return $this;
    }

    /**
     * Restrict to rows created within an inclusive date range.
     */
    public function whereDateRange(string $column, ?string $from, ?string $to): self
    {
        if ($from !== null && $from !== '') {
            $this->where($column, '>=', $from . ' 00:00:00');
        }

        if ($to !== null && $to !== '') {
            $this->where($column, '<=', $to . ' 23:59:59');
        }

        return $this;
    }

    /**
     * @param list<string> $columns
     */
    public function groupBy(array $columns): self
    {
        foreach ($columns as $column) {
            $this->groups[] = $this->quote($this->assertIdentifier($column));
        }

        return $this;
    }

    /**
     * Add a trusted HAVING fragment (aggregate comparisons).
     *
     * @param array<string,mixed> $bindings
     */
    public function havingRaw(string $expression, array $bindings = []): self
    {
        foreach ($bindings as $name => $value) {
            $this->bindings[ltrim($name, ':')] = $value;
        }

        $this->havings[] = $expression;

        return $this;
    }

    /**
     * Order by a column. The direction is normalised to ASC/DESC, so a caller
     * passing user input cannot inject a subquery here.
     */
    public function orderBy(string $column, string $direction = 'ASC'): self
    {
        $direction = strtoupper($direction) === 'DESC' ? 'DESC' : 'ASC';

        $this->orders[] = $this->quote($this->assertIdentifier($column)) . ' ' . $direction;

        return $this;
    }

    /**
     * Order by a trusted expression (for example a CASE ranking).
     */
    public function orderByRaw(string $expression): self
    {
        $this->orders[] = $expression;

        return $this;
    }

    public function limit(int $limit): self
    {
        $this->limit = max(0, $limit);

        return $this;
    }

    public function offset(int $offset): self
    {
        $this->offset = max(0, $offset);

        return $this;
    }

    /**
     * Apply page-based pagination.
     */
    public function forPage(int $page, int $perPage): self
    {
        return $this->limit($perPage)->offset(max(0, $page - 1) * $perPage);
    }

    // ------------------------------------------------------------------
    // Compilation and execution
    // ------------------------------------------------------------------

    /**
     * Compile the current state into a SELECT statement.
     */
    public function toSql(): string
    {
        if ($this->table === '') {
            throw new InvalidArgumentException('A query requires a table.');
        }

        $sql = 'SELECT ' . ($this->distinct ? 'DISTINCT ' : '') . implode(', ', $this->columns);
        $sql .= ' FROM ' . $this->quote($this->table);

        if ($this->tableAlias !== null) {
            $sql .= ' AS ' . $this->quote($this->tableAlias);
        }

        if ($this->joins !== []) {
            $sql .= ' ' . implode(' ', $this->joins);
        }

        if ($this->wheres !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $this->wheres);
        }

        if ($this->groups !== []) {
            $sql .= ' GROUP BY ' . implode(', ', $this->groups);
        }

        if ($this->havings !== []) {
            $sql .= ' HAVING ' . implode(' AND ', $this->havings);
        }

        if ($this->orders !== []) {
            $sql .= ' ORDER BY ' . implode(', ', $this->orders);
        }

        if ($this->limit !== null) {
            $sql .= ' LIMIT ' . $this->limit;
        }

        if ($this->offset !== null && $this->offset > 0) {
            $sql .= ' OFFSET ' . $this->offset;
        }

        return $sql;
    }

    /**
     * @return array<string,mixed>
     */
    public function bindings(): array
    {
        return $this->bindings;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function get(): array
    {
        return $this->connection->select($this->toSql(), $this->bindings);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function first(): ?array
    {
        $clone = clone $this;
        $clone->limit(1);

        return $this->connection->selectOne($clone->toSql(), $clone->bindings);
    }

    /**
     * Count the rows the current filters would return, ignoring pagination.
     */
    public function count(string $column = '*'): int
    {
        $clone = clone $this;
        $clone->orders  = [];
        $clone->limit   = null;
        $clone->offset  = null;

        // A grouped query counts groups, which requires a wrapping SELECT.
        if ($clone->groups !== []) {
            $inner = $clone->toSql();

            return (int) $this->connection->scalar(
                'SELECT COUNT(*) FROM (' . $inner . ') AS aggregated',
                $clone->bindings
            );
        }

        $clone->columns = [
            $column === '*'
                ? 'COUNT(*)'
                : 'COUNT(' . ($this->distinct ? 'DISTINCT ' : '') . $this->quote($this->assertIdentifier($column)) . ')',
        ];
        $clone->distinct = false;

        return (int) $this->connection->scalar($clone->toSql(), $clone->bindings);
    }

    /**
     * Run an aggregate over the current filters.
     */
    public function aggregate(string $function, string $column): float
    {
        $function = strtoupper($function);
        if (!in_array($function, ['SUM', 'AVG', 'MIN', 'MAX'], true)) {
            throw new InvalidArgumentException(sprintf('Unsupported aggregate "%s".', $function));
        }

        $clone = clone $this;
        $clone->columns = [$function . '(' . $this->quote($this->assertIdentifier($column)) . ')'];
        $clone->orders  = [];
        $clone->limit   = null;
        $clone->offset  = null;

        return (float) $this->connection->scalar($clone->toSql(), $clone->bindings);
    }

    public function exists(): bool
    {
        return $this->count() > 0;
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Register a bound value and return its placeholder name.
     */
    private function bind(mixed $value): string
    {
        $name = 'p' . $this->bindingSequence++;
        $this->bindings[$name] = $value;

        return $name;
    }

    /**
     * Validate an identifier, rejecting anything that is not a plain
     * table/column name (optionally dotted).
     *
     * @throws InvalidArgumentException When the identifier is unsafe.
     */
    private function assertIdentifier(string $identifier): string
    {
        $identifier = trim($identifier);

        if (preg_match(self::IDENTIFIER, $identifier) !== 1) {
            throw new InvalidArgumentException(sprintf('Unsafe SQL identifier "%s".', $identifier));
        }

        return $identifier;
    }

    /**
     * Backtick-quote a validated identifier, handling the dotted form.
     */
    private function quote(string $identifier): string
    {
        if ($identifier === '*') {
            return '*';
        }

        $parts = array_map(
            static fn (string $part): string => $part === '*' ? '*' : '`' . $part . '`',
            explode('.', $identifier)
        );

        return implode('.', $parts);
    }

    /**
     * Compile "column" or "column AS alias" into quoted SQL.
     */
    private function compileColumn(string $column): string
    {
        if (stripos($column, ' as ') !== false) {
            [$name, $alias] = preg_split('/\s+as\s+/i', $column, 2) ?: [$column, ''];

            return $this->quote($this->assertIdentifier(trim($name)))
                . ' AS ' . $this->quote($this->assertIdentifier(trim($alias)));
        }

        if ($column === '*') {
            return '*';
        }

        return $this->quote($this->assertIdentifier($column));
    }

    /**
     * Neutralise LIKE wildcards inside a user-supplied search term so that a
     * search for "100%" does not become a match-everything pattern.
     */
    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
