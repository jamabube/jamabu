<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * Per-recipient notification inbox.
 *
 * One row per recipient rather than a shared row with a read-state join, so
 * read and archive state is genuinely per user and the unread count is a single
 * indexed lookup.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class NotificationRepository extends BaseRepository
{
    protected string $table = 'notifications';
    protected string $primaryKey = 'notification_id';
    protected bool $timestamps = false;
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'type_key', 'title', 'description', 'priority', 'recipient_id', 'link',
        'icon', 'related_type', 'related_id', 'metadata', 'created_at',
    ];

    protected array $sortable = ['created_at', 'priority', 'is_read', 'type_key'];
    protected array $searchable = ['title', 'description', 'type_key'];

    /**
     * Deliver one notification to many recipients in a single statement.
     *
     * @param list<int>           $recipientIds
     * @param array<string,mixed> $notification
     *
     * @return int Number of rows created.
     */
    public function deliverToMany(array $recipientIds, array $notification): int
    {
        $recipientIds = array_values(array_unique(array_map(intval(...), $recipientIds)));

        if ($recipientIds === []) {
            return 0;
        }

        $rows     = [];
        $bindings = [];

        foreach ($recipientIds as $index => $recipientId) {
            $rows[] = sprintf(
                '(:type%1$d, :title%1$d, :description%1$d, :priority%1$d, :recipient%1$d, :link%1$d, :icon%1$d, :relatedType%1$d, :relatedId%1$d, :metadata%1$d, :createdAt%1$d)',
                $index
            );

            $bindings['type' . $index]        = $notification['type_key'];
            $bindings['title' . $index]       = mb_substr((string) $notification['title'], 0, 150);
            $bindings['description' . $index] = mb_substr((string) $notification['description'], 0, 500);
            $bindings['priority' . $index]    = $notification['priority'] ?? 'normal';
            $bindings['recipient' . $index]   = $recipientId;
            $bindings['link' . $index]        = $notification['link'] ?? null;
            $bindings['icon' . $index]        = $notification['icon'] ?? null;
            $bindings['relatedType' . $index] = $notification['related_type'] ?? null;
            $bindings['relatedId' . $index]   = $notification['related_id'] ?? null;
            $bindings['metadata' . $index]    = $notification['metadata'] ?? null;
            $bindings['createdAt' . $index]   = $notification['created_at'] ?? $this->timestamp();
        }

        return $this->connection->execute(
            'INSERT INTO `notifications`
                (`type_key`, `title`, `description`, `priority`, `recipient_id`, `link`,
                 `icon`, `related_type`, `related_id`, `metadata`, `created_at`)
             VALUES ' . implode(', ', $rows),
            $bindings
        );
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function filteredFor(int $recipientId, array $filters): QueryBuilder
    {
        $query = $this->query()->whereEquals('recipient_id', $recipientId);

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike($this->searchable, (string) $filters['search']);
        }

        foreach (['priority', 'type_key'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $query->whereEquals($column, (string) $filters[$column]);
            }
        }

        // The default view hides archived notifications; "archived" shows only
        // those, so nothing is ever silently unreachable.
        if (($filters['state'] ?? '') === 'unread') {
            $query->whereEquals('is_read', 0)->whereEquals('is_archived', 0);
        } elseif (($filters['state'] ?? '') === 'archived') {
            $query->whereEquals('is_archived', 1);
        } else {
            $query->whereEquals('is_archived', 0);
        }

        $query->whereDateRange('created_at', $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        return $query;
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginateFor(int $recipientId, array $filters, array $options): Paginator
    {
        $options['sort']      ??= 'created_at';
        $options['direction'] ??= 'DESC';

        return $this->paginateQuery($this->filteredFor($recipientId, $filters), $options);
    }

    /**
     * The dropdown feed: newest unread first.
     *
     * @return list<array<string,mixed>>
     */
    public function recentFor(int $recipientId, int $limit = 10): array
    {
        return $this->query()
            ->whereEquals('recipient_id', $recipientId)
            ->whereEquals('is_archived', 0)
            ->orderBy('is_read', 'ASC')
            ->orderBy('created_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    public function unreadCount(int $recipientId): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `notifications` WHERE `recipient_id` = ? AND `is_read` = 0 AND `is_archived` = 0',
            [$recipientId]
        );
    }

    /**
     * Unread counts split by priority, so a critical alert can be highlighted.
     *
     * @return array<string,int>
     */
    public function unreadByPriority(int $recipientId): array
    {
        $rows = $this->connection->select(
            'SELECT `priority`, COUNT(*) AS `total`
               FROM `notifications`
              WHERE `recipient_id` = ? AND `is_read` = 0 AND `is_archived` = 0
              GROUP BY `priority`',
            [$recipientId]
        );

        $counts = ['low' => 0, 'normal' => 0, 'high' => 0, 'critical' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['priority']] = (int) $row['total'];
        }

        return $counts;
    }

    public function markRead(int $notificationId, int $recipientId): int
    {
        return $this->connection->execute(
            'UPDATE `notifications` SET `is_read` = 1, `read_at` = :now
              WHERE `notification_id` = :id AND `recipient_id` = :recipient AND `is_read` = 0',
            ['now' => $this->timestamp(), 'id' => $notificationId, 'recipient' => $recipientId]
        );
    }

    public function markUnread(int $notificationId, int $recipientId): int
    {
        return $this->connection->execute(
            'UPDATE `notifications` SET `is_read` = 0, `read_at` = NULL
              WHERE `notification_id` = :id AND `recipient_id` = :recipient',
            ['id' => $notificationId, 'recipient' => $recipientId]
        );
    }

    public function markAllRead(int $recipientId): int
    {
        return $this->connection->execute(
            'UPDATE `notifications` SET `is_read` = 1, `read_at` = :now
              WHERE `recipient_id` = :recipient AND `is_read` = 0 AND `is_archived` = 0',
            ['now' => $this->timestamp(), 'recipient' => $recipientId]
        );
    }

    public function archive(int $notificationId, int $recipientId): int
    {
        return $this->connection->execute(
            'UPDATE `notifications` SET `is_archived` = 1, `archived_at` = :now, `is_read` = 1
              WHERE `notification_id` = :id AND `recipient_id` = :recipient',
            ['now' => $this->timestamp(), 'id' => $notificationId, 'recipient' => $recipientId]
        );
    }

    /**
     * Remove a notification, scoped to its owner so one user cannot delete
     * another's inbox item by guessing an identifier.
     */
    public function deleteFor(int $notificationId, int $recipientId): int
    {
        return $this->connection->execute(
            'DELETE FROM `notifications` WHERE `notification_id` = :id AND `recipient_id` = :recipient',
            ['id' => $notificationId, 'recipient' => $recipientId]
        );
    }

    /**
     * Prune read notifications past the retention window. Unread ones are kept
     * regardless of age: nobody has seen them yet.
     */
    public function prune(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }

        return $this->connection->execute(
            'DELETE FROM `notifications` WHERE `is_read` = 1 AND `created_at` < ?',
            [now()->modify('-' . $retentionDays . ' days')->format('Y-m-d H:i:s')]
        );
    }

    public function countPending(): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `notifications` WHERE `is_read` = 0 AND `is_archived` = 0'
        );
    }
}
