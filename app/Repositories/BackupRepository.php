<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;

/**
 * Backup history.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class BackupRepository extends BaseRepository
{
    protected string $table = 'backup_history';
    protected string $primaryKey = 'backup_id';
    protected bool $timestamps = false;
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'filename', 'backup_type', 'scope', 'file_size', 'checksum', 'table_count',
        'row_count', 'compressed', 'duration_ms', 'status', 'verified_at',
        'verification_result', 'error_message', 'created_by', 'created_at', 'completed_at',
    ];

    protected array $sortable = ['created_at', 'filename', 'file_size', 'status', 'backup_type'];
    protected array $searchable = ['filename', 'error_message'];

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginate(array $filters, array $options): Paginator
    {
        $query = $this->query();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike($this->searchable, (string) $filters['search']);
        }

        foreach (['status', 'backup_type', 'scope'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $query->whereEquals($column, (string) $filters[$column]);
            }
        }

        $options['sort']      ??= 'created_at';
        $options['direction'] ??= 'DESC';

        return $this->paginateQuery($query, $options);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByFilename(string $filename): ?array
    {
        return $this->findBy('filename', $filename);
    }

    /**
     * Mark a backup as finished.
     *
     * @param array<string,mixed> $result
     */
    public function complete(int $backupId, array $result): int
    {
        return $this->update($backupId, array_merge($result, [
            'completed_at' => $this->timestamp(),
        ]));
    }

    public function markFailed(int $backupId, string $message): int
    {
        return $this->update($backupId, [
            'status'        => 'failed',
            'error_message' => mb_substr($message, 0, 2000),
            'completed_at'  => $this->timestamp(),
        ]);
    }

    public function markRestored(int $backupId, int $restoredBy): int
    {
        return $this->connection->execute(
            'UPDATE `backup_history`
                SET `status` = :status, `restored_by` = :by, `restored_at` = :now
              WHERE `backup_id` = :id',
            ['status' => 'restored', 'by' => $restoredBy, 'now' => $this->timestamp(), 'id' => $backupId]
        );
    }

    public function markDeleted(int $backupId, int $deletedBy): int
    {
        return $this->connection->execute(
            'UPDATE `backup_history`
                SET `status` = :status, `deleted_by` = :by, `deleted_at` = :now
              WHERE `backup_id` = :id',
            ['status' => 'deleted', 'by' => $deletedBy, 'now' => $this->timestamp(), 'id' => $backupId]
        );
    }

    /**
     * The most recent successful backup.
     *
     * @return array<string,mixed>|null
     */
    public function latestSuccessful(): ?array
    {
        return $this->query()
            ->whereIn('status', ['completed', 'verified'])
            ->orderBy('created_at', 'DESC')
            ->first();
    }

    /**
     * Backups eligible for pruning, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function beyondRetention(int $keep): array
    {
        $retained = $this->query()
            ->select(['backup_id'])
            ->whereIn('status', ['completed', 'verified'])
            ->orderBy('created_at', 'DESC')
            ->limit(max(1, $keep))
            ->get();

        $retainedIds = array_map(static fn (array $row): int => (int) $row['backup_id'], $retained);

        return $this->query()
            ->whereIn('status', ['completed', 'verified'])
            ->whereIn('backup_id', $retainedIds, true)
            ->orderBy('created_at', 'ASC')
            ->get();
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        $row = $this->connection->selectOne(
            "SELECT COUNT(*) AS `total`,
                    SUM(`status` IN ('completed','verified')) AS `successful`,
                    SUM(`status` = 'failed') AS `failed`,
                    SUM(CASE WHEN `status` IN ('completed','verified') THEN `file_size` ELSE 0 END) AS `total_bytes`,
                    MAX(`created_at`) AS `last_run`
               FROM `backup_history`"
        ) ?? [];

        return [
            'total'       => (int) ($row['total'] ?? 0),
            'successful'  => (int) ($row['successful'] ?? 0),
            'failed'      => (int) ($row['failed'] ?? 0),
            'total_bytes' => (int) ($row['total_bytes'] ?? 0),
            'last_run'    => $row['last_run'] ?? null,
        ];
    }
}
