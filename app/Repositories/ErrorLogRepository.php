<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * Application error storage.
 *
 * Identical failures are folded onto one row by fingerprint, so a request loop
 * throwing the same exception a thousand times produces one record with a
 * count rather than a thousand rows an administrator has to page through.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class ErrorLogRepository extends BaseRepository
{
    protected string $table = 'error_logs';
    protected string $primaryKey = 'error_log_id';
    protected bool $timestamps = false;
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'reference', 'module', 'controller', 'method', 'severity', 'exception_class',
        'message', 'file', 'line', 'stack_trace', 'context', 'user_id', 'device_id',
        'ip_address', 'request_id', 'request_method', 'request_path', 'fingerprint',
        'first_seen_at', 'last_seen_at', 'created_at',
    ];

    protected array $sortable = ['last_seen_at', 'first_seen_at', 'severity', 'module', 'occurrence_count', 'resolved'];
    protected array $searchable = ['reference', 'module', 'controller', 'message', 'exception_class', 'request_path'];

    /**
     * Record an occurrence, folding it onto an existing unresolved row when the
     * same failure has already been seen.
     *
     * @param array<string,mixed> $attributes
     *
     * @return array{id:int,reference:string,is_new:bool}
     */
    public function recordOccurrence(array $attributes): array
    {
        $fingerprint = (string) $attributes['fingerprint'];

        // Only unresolved rows absorb a new occurrence: once an administrator
        // has marked a fault resolved, a fresh occurrence means it came back
        // and deserves its own record.
        $existing = $this->connection->selectOne(
            'SELECT `error_log_id`, `reference`, `occurrence_count`
               FROM `error_logs`
              WHERE `fingerprint` = :fingerprint AND `resolved` = 0
              ORDER BY `error_log_id` DESC
              LIMIT 1',
            ['fingerprint' => $fingerprint]
        );

        if ($existing !== null) {
            $this->connection->execute(
                'UPDATE `error_logs`
                    SET `occurrence_count` = `occurrence_count` + 1,
                        `last_seen_at` = :lastSeen,
                        `request_id` = :requestId,
                        `request_path` = :requestPath
                  WHERE `error_log_id` = :id',
                [
                    'lastSeen'    => $attributes['last_seen_at'],
                    'requestId'   => $attributes['request_id'] ?? null,
                    'requestPath' => $attributes['request_path'] ?? null,
                    'id'          => (int) $existing['error_log_id'],
                ]
            );

            return [
                'id'        => (int) $existing['error_log_id'],
                'reference' => (string) $existing['reference'],
                'is_new'    => false,
            ];
        }

        return [
            'id'        => $this->create($attributes),
            'reference' => (string) $attributes['reference'],
            'is_new'    => true,
        ];
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

        foreach (['severity', 'module', 'user_id', 'device_id', 'assigned_to'] as $column) {
            if (($filters[$column] ?? '') !== '' && ($filters[$column] ?? null) !== null) {
                $query->whereEquals($column, $filters[$column]);
            }
        }

        if (($filters['resolved'] ?? '') !== '') {
            $query->whereEquals('resolved', (int) (bool) $filters['resolved']);
        }

        $query->whereDateRange('last_seen_at', $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        return $query;
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginate(array $filters, array $options): Paginator
    {
        $options['sort']      ??= 'last_seen_at';
        $options['direction'] ??= 'DESC';

        return $this->paginateQuery($this->filtered($filters), $options);
    }

    /**
     * Record an administrator's resolution without touching the diagnostics.
     */
    public function resolve(int $id, int $resolvedBy, string $notes): int
    {
        return $this->connection->execute(
            'UPDATE `error_logs`
                SET `resolved` = 1, `resolved_by` = :by, `resolved_at` = :at, `resolution_notes` = :notes
              WHERE `error_log_id` = :id',
            [
                'by'    => $resolvedBy,
                'at'    => $this->timestamp(),
                'notes' => $notes,
                'id'    => $id,
            ]
        );
    }

    public function reopen(int $id): int
    {
        return $this->connection->execute(
            'UPDATE `error_logs` SET `resolved` = 0, `resolved_by` = NULL, `resolved_at` = NULL WHERE `error_log_id` = :id',
            ['id' => $id]
        );
    }

    public function assign(int $id, ?int $userId): int
    {
        return $this->connection->execute(
            'UPDATE `error_logs` SET `assigned_to` = :user WHERE `error_log_id` = :id',
            ['user' => $userId, 'id' => $id]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByReference(string $reference): ?array
    {
        return $this->findBy('reference', $reference);
    }

    public function countUnresolved(): int
    {
        return $this->query()->whereEquals('resolved', 0)->count();
    }

    public function countSince(string $since): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `error_logs` WHERE `last_seen_at` >= ?',
            [$since]
        );
    }

    /**
     * Unresolved errors, most severe and most frequent first.
     *
     * @return list<array<string,mixed>>
     */
    public function recentUnresolved(int $limit = 5): array
    {
        return $this->query()
            ->whereEquals('resolved', 0)
            ->orderByRaw("FIELD(`severity`, 'emergency', 'alert', 'critical', 'error', 'warning', 'notice')")
            ->orderBy('last_seen_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Remove resolved errors older than the retention period.
     *
     * Only resolved ones. An unresolved error is an open fault, and a fault
     * does not stop existing because it has been on the list a long time —
     * if anything, that makes it more worth keeping.
     *
     * @param int $retentionDays 0 retains indefinitely.
     */
    public function prune(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }

        return $this->connection->execute(
            'DELETE FROM `error_logs` WHERE `resolved` = 1 AND `last_seen_at` < ?',
            [now()->modify('-' . $retentionDays . ' days')->format('Y-m-d H:i:s')]
        );
    }

    /**
     * @return list<string>
     */
    public function modules(): array
    {
        return array_map(strval(...), $this->connection->column(
            'SELECT DISTINCT `module` FROM `error_logs` ORDER BY `module`'
        ));
    }
}
