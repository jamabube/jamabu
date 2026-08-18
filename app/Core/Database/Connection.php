<?php

declare(strict_types=1);

namespace App\Core\Database;

use App\Exceptions\DatabaseException;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * PDO connection wrapper.
 *
 * Every query in the system runs through this class. It enforces three rules
 * that the specification treats as non-negotiable:
 *
 *   1. Statements are always prepared and parameters always bound. There is no
 *      method that accepts an interpolated value.
 *   2. Errors surface as exceptions and are wrapped so no driver message
 *      (which can disclose schema detail) escapes to a client.
 *   3. Transactions nest safely via savepoints, so a service can open one
 *      without knowing whether a caller already did.
 *
 * @package App\Core\Database
 * @version 1.0.0
 */
class Connection
{
    private ?PDO $pdo = null;

    /** Depth of the current logical transaction. */
    private int $transactionLevel = 0;

    /** @var list<array{sql:string,bindings:array<string,mixed>,time_ms:float}> */
    private array $queryLog = [];

    private bool $logQueries = false;

    private int $queryCount = 0;

    /**
     * @param array<string,mixed> $config Connection settings from config/database.php.
     */
    public function __construct(private readonly array $config)
    {
    }

    /**
     * Lazily open the underlying PDO handle.
     *
     * @throws DatabaseException When the server cannot be reached.
     */
    public function pdo(): PDO
    {
        if ($this->pdo instanceof PDO) {
            return $this->pdo;
        }

        $driver = (string) ($this->config['driver'] ?? 'mysql');

        try {
            $this->pdo = $driver === 'sqlite'
                ? $this->connectSqlite()
                : $this->connectMysql();
        } catch (PDOException $e) {
            // The DSN can contain the database host and user name; the wrapper
            // keeps them in the log context and out of the client response.
            throw DatabaseException::fromThrowable($e, 'connect', [
                'driver'   => $driver,
                'host'     => $this->config['host'] ?? null,
                'database' => $this->config['database'] ?? null,
            ]);
        }

        return $this->pdo;
    }

    private function connectMysql(): PDO
    {
        $socket = (string) ($this->config['socket'] ?? '');

        $dsn = $socket !== ''
            ? sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $this->config['database'], $this->config['charset'])
            : sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->config['host'],
                (int) ($this->config['port'] ?? 3306),
                $this->config['database'],
                $this->config['charset']
            );

        $pdo = new PDO(
            $dsn,
            (string) $this->config['username'],
            (string) $this->config['password'],
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                // Real prepared statements: the value never reaches the server
                // inside the SQL text, which is what makes injection impossible
                // rather than merely unlikely.
                PDO::ATTR_EMULATE_PREPARES   => false,
                PDO::ATTR_STRINGIFY_FETCHES  => false,
                PDO::ATTR_PERSISTENT         => (bool) ($this->config['persistent'] ?? false),
            ]
        );

        // A strict server mode turns silent truncation into an error, which
        // keeps the data honest.
        if (($this->config['strict'] ?? true) === true) {
            $pdo->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");
        }

        $timezone = (string) ($this->config['timezone'] ?? '+00:00');
        $statement = $pdo->prepare('SET time_zone = ?');
        $statement->execute([$timezone]);

        return $pdo;
    }

    private function connectSqlite(): PDO
    {
        $pdo = new PDO(
            'sqlite:' . (string) ($this->config['database'] ?? ':memory:'),
            null,
            null,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );

        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    /**
     * Run a prepared statement and return the raw PDOStatement.
     *
     * @param array<string|int,mixed> $bindings
     *
     * @throws DatabaseException
     */
    public function run(string $sql, array $bindings = []): PDOStatement
    {
        $startedAt = microtime(true);

        try {
            $statement = $this->pdo()->prepare($sql);

            foreach ($bindings as $key => $value) {
                $parameter = is_int($key) ? $key + 1 : (str_starts_with($key, ':') ? $key : ':' . $key);
                $statement->bindValue($parameter, $value, $this->parameterType($value));
            }

            $statement->execute();
        } catch (PDOException $e) {
            throw DatabaseException::fromThrowable($e, 'query', [
                'sql'      => $sql,
                'bindings' => array_keys($bindings),
            ]);
        }

        $this->recordQuery($sql, $bindings, (microtime(true) - $startedAt) * 1000);

        return $statement;
    }

    /**
     * Map a PHP value onto the correct PDO parameter type.
     */
    private function parameterType(mixed $value): int
    {
        return match (true) {
            is_int($value)  => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default         => PDO::PARAM_STR,
        };
    }

    /**
     * Fetch every matching row.
     *
     * @param array<string|int,mixed> $bindings
     *
     * @return list<array<string,mixed>>
     */
    public function select(string $sql, array $bindings = []): array
    {
        /** @var list<array<string,mixed>> $rows */
        $rows = $this->run($sql, $bindings)->fetchAll();

        return $rows;
    }

    /**
     * Fetch the first matching row, or null.
     *
     * @param array<string|int,mixed> $bindings
     *
     * @return array<string,mixed>|null
     */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        /** @var array<string,mixed>|false $row */
        $row = $this->run($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Fetch a single scalar value from the first column of the first row.
     *
     * @param array<string|int,mixed> $bindings
     */
    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->run($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Fetch the first column of every row as a flat list.
     *
     * @param array<string|int,mixed> $bindings
     *
     * @return list<mixed>
     */
    public function column(string $sql, array $bindings = []): array
    {
        /** @var list<mixed> $values */
        $values = $this->run($sql, $bindings)->fetchAll(PDO::FETCH_COLUMN);

        return $values;
    }

    /**
     * Execute an INSERT and return the generated primary key.
     *
     * @param array<string|int,mixed> $bindings
     */
    public function insert(string $sql, array $bindings = []): int
    {
        $this->run($sql, $bindings);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Execute a write statement and return the affected row count.
     *
     * @param array<string|int,mixed> $bindings
     */
    public function execute(string $sql, array $bindings = []): int
    {
        return $this->run($sql, $bindings)->rowCount();
    }

    /**
     * Execute raw DDL. Only ever called by the migration runner, never with
     * user-supplied input.
     *
     * @throws DatabaseException
     */
    public function unprepared(string $sql): void
    {
        try {
            $this->pdo()->exec($sql);
        } catch (PDOException $e) {
            throw DatabaseException::fromThrowable($e, 'unprepared', ['sql' => substr($sql, 0, 500)]);
        }
    }

    // ------------------------------------------------------------------
    // Transactions
    // ------------------------------------------------------------------

    /**
     * Begin a transaction, or create a savepoint when one is already open.
     */
    public function beginTransaction(): void
    {
        if ($this->transactionLevel === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec('SAVEPOINT ' . $this->savepointName($this->transactionLevel));
        }

        $this->transactionLevel++;
    }

    /**
     * Commit the current transaction or release the current savepoint.
     */
    public function commit(): void
    {
        if ($this->transactionLevel === 0) {
            return;
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            $this->pdo()->commit();

            return;
        }

        $this->pdo()->exec('RELEASE SAVEPOINT ' . $this->savepointName($this->transactionLevel));
    }

    /**
     * Roll back to the start of the current transaction or savepoint.
     */
    public function rollBack(): void
    {
        if ($this->transactionLevel === 0) {
            return;
        }

        $this->transactionLevel--;

        if ($this->transactionLevel === 0) {
            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }

            return;
        }

        $this->pdo()->exec('ROLLBACK TO SAVEPOINT ' . $this->savepointName($this->transactionLevel));
    }

    /**
     * Run a callback inside a transaction, rolling back on any throwable.
     *
     * This is the only sanctioned way to perform a multi-statement write:
     * partial data can never survive a failure.
     *
     * @template T
     * @param callable():T $callback
     *
     * @return T
     *
     * @throws Throwable Whatever the callback raised, after the rollback.
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback();
            $this->commit();

            return $result;
        } catch (Throwable $e) {
            $this->rollBack();

            throw $e;
        }
    }

    public function inTransaction(): bool
    {
        return $this->transactionLevel > 0;
    }

    public function transactionLevel(): int
    {
        return $this->transactionLevel;
    }

    private function savepointName(int $level): string
    {
        return 'vams_sp_' . $level;
    }

    // ------------------------------------------------------------------
    // Diagnostics
    // ------------------------------------------------------------------

    public function enableQueryLog(bool $enabled = true): void
    {
        $this->logQueries = $enabled;
    }

    /**
     * @param array<string|int,mixed> $bindings
     */
    private function recordQuery(string $sql, array $bindings, float $milliseconds): void
    {
        $this->queryCount++;

        $threshold = (int) config('database.slow_query_ms', 500);
        if ($threshold > 0 && $milliseconds >= $threshold) {
            // A slow query is a maintenance signal, not an error: log and move on.
            logger()->channel('performance')->warning('Slow query detected', [
                'sql'     => $sql,
                'time_ms' => round($milliseconds, 2),
            ]);
        }

        if ($this->logQueries) {
            $this->queryLog[] = [
                'sql'      => $sql,
                'bindings' => $bindings,
                'time_ms'  => round($milliseconds, 3),
            ];
        }
    }

    /**
     * @return list<array{sql:string,bindings:array<string,mixed>,time_ms:float}>
     */
    public function queryLog(): array
    {
        return $this->queryLog;
    }

    public function queryCount(): int
    {
        return $this->queryCount;
    }

    /**
     * Verify the connection is alive. Used by the system health dashboard.
     */
    public function isHealthy(): bool
    {
        try {
            $this->pdo()->query('SELECT 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function driver(): string
    {
        return (string) ($this->config['driver'] ?? 'mysql');
    }

    public function databaseName(): string
    {
        return (string) ($this->config['database'] ?? '');
    }

    /**
     * The MySQL server version, or an empty string when unavailable.
     */
    public function serverVersion(): string
    {
        try {
            return (string) $this->pdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Close the connection. Used between test cases.
     */
    public function disconnect(): void
    {
        $this->pdo = null;
        $this->transactionLevel = 0;
    }
}
