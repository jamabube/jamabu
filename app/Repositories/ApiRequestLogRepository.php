<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * Per-request API telemetry.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class ApiRequestLogRepository extends BaseRepository
{
    protected string $table = 'api_request_logs';
    protected string $primaryKey = 'api_request_log_id';
    protected bool $timestamps = false;
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'request_id', 'endpoint', 'route_name', 'method', 'user_id', 'device_id',
        'ip_address', 'user_agent', 'status_code', 'error_code', 'duration_ms',
        'query_count', 'request_bytes', 'response_bytes', 'payload', 'created_at',
    ];

    protected array $sortable = ['created_at', 'endpoint', 'status_code', 'duration_ms', 'method'];
    protected array $searchable = ['endpoint', 'route_name', 'request_id', 'ip_address', 'error_code'];

    /**
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->query();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike($this->searchable, (string) $filters['search']);
        }

        foreach (['device_id', 'user_id', 'method', 'status_code'] as $column) {
            if (($filters[$column] ?? '') !== '' && ($filters[$column] ?? null) !== null) {
                $query->whereEquals($column, $filters[$column]);
            }
        }

        // "failed" groups every non-2xx response, which is what an
        // administrator actually wants to look at.
        if (($filters['outcome'] ?? '') === 'failed') {
            $query->where('status_code', '>=', 400);
        } elseif (($filters['outcome'] ?? '') === 'succeeded') {
            $query->where('status_code', '<', 400);
        }

        if (($filters['slow_only'] ?? false) === true) {
            $query->where('duration_ms', '>=', (float) config('api.logging.slow_request_ms', 2000));
        }

        $query->whereDateRange('created_at', $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        return $query;
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginate(array $filters, array $options): Paginator
    {
        $options['sort']      ??= 'created_at';
        $options['direction'] ??= 'DESC';

        return $this->paginateQuery($this->filtered($filters), $options);
    }

    /**
     * Aggregate performance figures for the health dashboard.
     *
     * @return array{total:int,failed:int,average_ms:float,slowest_ms:float,p95_ms:float}
     */
    public function performanceSince(string $since): array
    {
        $row = $this->connection->selectOne(
            'SELECT COUNT(*) AS `total`,
                    SUM(`status_code` >= 400) AS `failed`,
                    AVG(`duration_ms`) AS `average_ms`,
                    MAX(`duration_ms`) AS `slowest_ms`
               FROM `api_request_logs`
              WHERE `created_at` >= :since',
            ['since' => $since]
        );

        return [
            'total'      => (int) ($row['total'] ?? 0),
            'failed'     => (int) ($row['failed'] ?? 0),
            'average_ms' => round((float) ($row['average_ms'] ?? 0), 2),
            'slowest_ms' => round((float) ($row['slowest_ms'] ?? 0), 2),
            'p95_ms'     => $this->percentile($since, 0.95),
        ];
    }

    /**
     * Approximate a percentile with an OFFSET scan.
     *
     * MySQL 8 has window functions, but this form also runs on the MariaDB
     * builds found in XAMPP distributions and is fast enough over an indexed
     * duration column.
     */
    private function percentile(string $since, float $fraction): float
    {
        $total = (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `api_request_logs` WHERE `created_at` >= ?',
            [$since]
        );

        if ($total === 0) {
            return 0.0;
        }

        $offset = max(0, (int) floor($total * $fraction) - 1);

        $value = $this->connection->scalar(
            'SELECT `duration_ms` FROM `api_request_logs`
              WHERE `created_at` >= ?
              ORDER BY `duration_ms` ASC
              LIMIT 1 OFFSET ' . $offset,
            [$since]
        );

        return round((float) ($value ?? 0), 2);
    }

    /**
     * Busiest endpoints in a period.
     *
     * @return list<array<string,mixed>>
     */
    public function busiestEndpoints(string $since, int $limit = 10): array
    {
        return $this->connection->select(
            'SELECT `endpoint`, `method`, COUNT(*) AS `calls`,
                    ROUND(AVG(`duration_ms`), 2) AS `average_ms`,
                    SUM(`status_code` >= 400) AS `failures`
               FROM `api_request_logs`
              WHERE `created_at` >= :since
              GROUP BY `endpoint`, `method`
              ORDER BY `calls` DESC
              LIMIT ' . max(1, $limit),
            ['since' => $since]
        );
    }

    /**
     * Communication history for one device.
     *
     * @return list<array<string,mixed>>
     */
    public function forDevice(int $deviceId, int $limit = 50): array
    {
        return $this->query()
            ->whereEquals('device_id', $deviceId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Delete telemetry older than the retention window.
     */
    public function prune(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }

        return $this->connection->execute(
            'DELETE FROM `api_request_logs` WHERE `created_at` < ?',
            [now()->modify('-' . $retentionDays . ' days')->format('Y-m-d H:i:s')]
        );
    }
}
