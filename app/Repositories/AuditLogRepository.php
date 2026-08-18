<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * Audit trail storage.
 *
 * Deliberately write-once: this class offers insert and read methods and no
 * update or delete of any kind. There is no code path in the application that
 * can modify an audit record, which is what makes the trail trustworthy.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class AuditLogRepository extends BaseRepository
{
    protected string $table = 'audit_logs';
    protected string $primaryKey = 'audit_log_id';
    protected bool $timestamps = false;
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'user_id', 'username', 'role_name', 'device_id', 'module', 'action', 'description',
        'record_type', 'record_id', 'old_values', 'new_values', 'ip_address', 'user_agent',
        'browser', 'platform', 'request_id', 'request_method', 'request_path', 'status', 'created_at',
    ];

    protected array $sortable = ['created_at', 'module', 'action', 'username', 'status'];
    protected array $searchable = ['username', 'module', 'action', 'description', 'record_type', 'ip_address'];

    /**
     * Audit records are immutable; this override makes the guarantee explicit
     * rather than relying on nobody happening to call update().
     */
    public function update(int $id, array $attributes): int
    {
        throw new \LogicException('Audit records are immutable and cannot be updated.');
    }

    public function delete(int $id, ?int $deletedBy = null): int
    {
        throw new \LogicException('Audit records are immutable and cannot be deleted.');
    }

    public function forceDelete(int $id): int
    {
        throw new \LogicException('Audit records are immutable and cannot be deleted.');
    }

    /**
     * Build a filtered query for the audit viewer.
     *
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->query();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike($this->searchable, (string) $filters['search']);
        }

        foreach (['user_id' => 'user_id', 'device_id' => 'device_id', 'module' => 'module',
                  'action' => 'action', 'status' => 'status', 'record_type' => 'record_type'] as $filter => $column) {
            if (($filters[$filter] ?? '') !== '' && ($filters[$filter] ?? null) !== null) {
                $query->whereEquals($column, $filters[$filter]);
            }
        }

        if (($filters['ip_address'] ?? '') !== '') {
            $query->whereEquals('ip_address', (string) $filters['ip_address']);
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
     * The most recent records, for the dashboard activity panel.
     *
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit = 10): array
    {
        return $this->query()
            ->select(['audit_log_id', 'username', 'module', 'action', 'description', 'status', 'created_at'])
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Every audit record touching one entity, for its history timeline.
     *
     * @return list<array<string,mixed>>
     */
    public function forRecord(string $recordType, int|string $recordId, int $limit = 100): array
    {
        return $this->query()
            ->whereEquals('record_type', $recordType)
            ->whereEquals('record_id', (string) $recordId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Activity attributable to one user.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(int $userId, int $limit = 50): array
    {
        return $this->query()
            ->whereEquals('user_id', $userId)
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Distinct values for the viewer's filter drop-downs.
     *
     * @return array{modules:list<string>,actions:list<string>}
     */
    public function filterOptions(): array
    {
        /** @var list<string> $modules */
        $modules = array_map(strval(...), $this->connection->column(
            'SELECT DISTINCT `module` FROM `audit_logs` ORDER BY `module`'
        ));

        /** @var list<string> $actions */
        $actions = array_map(strval(...), $this->connection->column(
            'SELECT DISTINCT `action` FROM `audit_logs` ORDER BY `action`'
        ));

        return ['modules' => $modules, 'actions' => $actions];
    }

    /**
     * Counts per action for a period, backing the audit summary widget.
     *
     * @return list<array<string,mixed>>
     */
    public function summaryByAction(string $from, string $to, int $limit = 10): array
    {
        return $this->connection->select(
            'SELECT `action`, COUNT(*) AS `total`
               FROM `audit_logs`
              WHERE `created_at` BETWEEN :from AND :to
              GROUP BY `action`
              ORDER BY `total` DESC
              LIMIT ' . max(1, $limit),
            ['from' => $from, 'to' => $to]
        );
    }

    public function countSince(string $since): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `audit_logs` WHERE `created_at` >= ?',
            [$since]
        );
    }
}
