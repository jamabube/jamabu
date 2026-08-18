<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * Security event storage.
 *
 * Events are never deleted through the application. An administrator may
 * triage one — acknowledge it, record a resolution — but the original
 * description, evidence and timestamp are never rewritten.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class SecurityEventRepository extends BaseRepository
{
    protected string $table = 'security_events';
    protected string $primaryKey = 'security_event_id';
    protected bool $timestamps = false;
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'event_type', 'severity', 'description', 'detail', 'user_id', 'username',
        'device_id', 'device_code', 'ip_address', 'user_agent', 'request_id',
        'request_method', 'request_path', 'action_taken', 'status', 'occurred_at',
    ];

    protected array $sortable = ['occurred_at', 'event_type', 'severity', 'status', 'ip_address'];
    protected array $searchable = ['event_type', 'description', 'username', 'device_code', 'ip_address', 'action_taken'];

    /** Only triage columns may ever be written after creation. */
    private const TRIAGE_COLUMNS = ['status', 'acknowledged_by', 'acknowledged_at', 'resolution_notes'];

    public function delete(int $id, ?int $deletedBy = null): int
    {
        throw new \LogicException('Security events are retained permanently and cannot be deleted.');
    }

    public function forceDelete(int $id): int
    {
        throw new \LogicException('Security events are retained permanently and cannot be deleted.');
    }

    /**
     * Update only the triage columns; the evidence is immutable.
     *
     * @param array<string,mixed> $attributes
     */
    public function update(int $id, array $attributes): int
    {
        $triage = array_intersect_key($attributes, array_flip(self::TRIAGE_COLUMNS));

        if ($triage === []) {
            return 0;
        }

        $assignments = [];
        foreach (array_keys($triage) as $column) {
            $assignments[] = sprintf('`%s` = :%s', $column, $column);
        }

        $triage['__pk'] = $id;

        return $this->connection->execute(
            sprintf(
                'UPDATE `security_events` SET %s WHERE `security_event_id` = :__pk',
                implode(', ', $assignments)
            ),
            $triage
        );
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->query();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike($this->searchable, (string) $filters['search']);
        }

        foreach (['event_type', 'severity', 'status', 'ip_address', 'device_id', 'user_id'] as $column) {
            if (($filters[$column] ?? '') !== '' && ($filters[$column] ?? null) !== null) {
                $query->whereEquals($column, $filters[$column]);
            }
        }

        $query->whereDateRange('occurred_at', $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        return $query;
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginate(array $filters, array $options): Paginator
    {
        $options['sort']      ??= 'occurred_at';
        $options['direction'] ??= 'DESC';

        return $this->paginateQuery($this->filtered($filters), $options);
    }

    /**
     * Unresolved events, most severe first, for the dashboard alert panel.
     *
     * @return list<array<string,mixed>>
     */
    public function activeAlerts(int $limit = 8): array
    {
        return $this->query()
            ->whereIn('status', ['new', 'investigating'])
            // FIELD() orders by severity rather than alphabetically, so a
            // critical alert is never buried under a low one.
            ->orderByRaw("FIELD(`severity`, 'critical', 'high', 'medium', 'low')")
            ->orderBy('occurred_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * How many events of each severity occurred since a moment.
     *
     * @return array<string,int>
     */
    public function severityCounts(string $since): array
    {
        $rows = $this->connection->select(
            'SELECT `severity`, COUNT(*) AS `total`
               FROM `security_events`
              WHERE `occurred_at` >= ?
              GROUP BY `severity`',
            [$since]
        );

        $counts = ['low' => 0, 'medium' => 0, 'high' => 0, 'critical' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['severity']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Count events of one type from one source inside a time window.
     *
     * This is the query the detection engine runs to decide whether repeated
     * behaviour has crossed a threshold.
     */
    public function countRecent(string $eventType, string $ipAddress, int $windowSeconds): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `security_events`
              WHERE `event_type` = :type AND `ip_address` = :ip
                AND `occurred_at` >= :since',
            [
                'type'  => $eventType,
                'ip'    => $ipAddress,
                'since' => now()->modify('-' . max(1, $windowSeconds) . ' seconds')->format('Y-m-d H:i:s'),
            ]
        );
    }

    public function countUnresolved(): int
    {
        return $this->query()->whereIn('status', ['new', 'investigating'])->count();
    }

    public function countSince(string $since): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `security_events` WHERE `occurred_at` >= ?',
            [$since]
        );
    }

    /**
     * Daily counts for the security trend chart.
     *
     * @return list<array<string,mixed>>
     */
    public function dailyTrend(string $from, string $to): array
    {
        return $this->connection->select(
            "SELECT DATE(`occurred_at`) AS `day`,
                    COUNT(*) AS `total`,
                    SUM(`severity` = 'critical') AS `critical`,
                    SUM(`severity` = 'high') AS `high`
               FROM `security_events`
              WHERE `occurred_at` BETWEEN :from AND :to
              GROUP BY DATE(`occurred_at`)
              ORDER BY `day`",
            ['from' => $from, 'to' => $to]
        );
    }

    /**
     * Distinct event types present, for the filter drop-down.
     *
     * @return list<string>
     */
    public function eventTypes(): array
    {
        return array_map(strval(...), $this->connection->column(
            'SELECT DISTINCT `event_type` FROM `security_events` ORDER BY `event_type`'
        ));
    }
}
