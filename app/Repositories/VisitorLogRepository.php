<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * Visitor passes, which double as the visit record.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class VisitorLogRepository extends BaseRepository
{
    protected string $table = 'visitor_logs';
    protected string $primaryKey = 'visitor_log_id';
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'pass_reference', 'visitor_id', 'rfid_card_id', 'purpose', 'destination',
        'vehicle_plate', 'vehicle_description', 'companions', 'authorized_by', 'issued_by',
        'issued_at', 'valid_from', 'valid_until', 'entry_time', 'exit_time', 'status',
        'revoked_by', 'revoked_at', 'revoke_reason', 'remarks',
    ];

    protected array $sortable = [
        'issued_at', 'valid_until', 'entry_time', 'exit_time', 'status',
        'visitor_name', 'pass_reference', 'duration_seconds',
    ];

    protected array $searchable = [
        'pass_reference', 'visitor_name', 'company', 'purpose', 'vehicle_plate', 'card_code',
    ];

    /**
     * The activity view, carrying the visitor, type, card and authoriser.
     */
    public function activity(): QueryBuilder
    {
        return (new QueryBuilder($this->connection))->table('v_visitor_activity');
    }

    /**
     * The open pass holding a given card, if any.
     *
     * @return array<string,mixed>|null
     */
    public function openPassForCard(int $cardId): ?array
    {
        return $this->activity()
            ->whereEquals('rfid_card_id', $cardId)
            ->whereIn('status', ['issued', 'inside'])
            ->orderBy('issued_at', 'DESC')
            ->first();
    }

    /**
     * Resolve a scanned card UID to the pass currently using it.
     *
     * @return array<string,mixed>|null
     */
    public function openPassForCardUid(string $cardUid): ?array
    {
        return $this->connection->selectOne(
            "SELECT vl.*, v.`full_name` AS `visitor_name`, v.`is_blacklisted`, v.`status` AS `visitor_status`,
                    c.`rfid_card_id`, c.`card_code`, c.`status` AS `card_status`
               FROM `rfid_cards` c
               LEFT JOIN `visitor_logs` vl ON vl.`rfid_card_id` = c.`rfid_card_id`
                    AND vl.`status` IN ('issued', 'inside')
               LEFT JOIN `visitors` v ON v.`visitor_id` = vl.`visitor_id`
              WHERE c.`card_uid` = :uid AND c.`deleted_at` IS NULL
              LIMIT 1",
            ['uid' => \App\Core\Support\Str::normaliseUid($cardUid)]
        );
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->activity();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike($this->searchable, (string) $filters['search']);
        }

        foreach (['status', 'visitor_id', 'rfid_card_id', 'visitor_type'] as $column) {
            if (($filters[$column] ?? '') !== '' && ($filters[$column] ?? null) !== null) {
                $query->whereEquals($column, $filters[$column]);
            }
        }

        if (($filters['overdue'] ?? false) === true) {
            $query->whereEquals('is_overdue', 1);
        }

        $query->whereDateRange('issued_at', $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        return $query;
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginate(array $filters, array $options): Paginator
    {
        $query = $this->filtered($filters);
        $query->orderBy($this->assertSortable((string) ($options['sort'] ?? 'issued_at')), (string) ($options['direction'] ?? 'DESC'));

        return Paginator::fromQuery(
            $query,
            max(1, (int) ($options['page'] ?? 1)),
            (int) ($options['per_page'] ?? config('app.pagination.default_per_page', 25))
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findInView(int $visitorLogId): ?array
    {
        return $this->activity()->whereEquals('visitor_log_id', $visitorLogId)->first();
    }

    /**
     * Passes issued to one visitor.
     *
     * @return list<array<string,mixed>>
     */
    public function forVisitor(int $visitorId, int $limit = 50): array
    {
        return $this->activity()
            ->whereEquals('visitor_id', $visitorId)
            ->orderBy('issued_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Passes still open right now.
     *
     * @return list<array<string,mixed>>
     */
    public function currentlyInside(): array
    {
        return $this->activity()
            ->whereEquals('status', 'inside')
            ->orderBy('entry_time', 'DESC')
            ->get();
    }

    public function markEntered(int $visitorLogId, string $entryTime): int
    {
        return $this->connection->execute(
            "UPDATE `visitor_logs`
                SET `entry_time` = COALESCE(`entry_time`, :entryTime), `status` = 'inside', `updated_at` = :now
              WHERE `visitor_log_id` = :id AND `status` = 'issued'",
            ['entryTime' => $entryTime, 'now' => $this->timestamp(), 'id' => $visitorLogId]
        );
    }

    public function markExited(int $visitorLogId, string $exitTime): int
    {
        return $this->connection->execute(
            "UPDATE `visitor_logs`
                SET `exit_time` = :exitTime, `status` = 'completed', `updated_at` = :now
              WHERE `visitor_log_id` = :id AND `status` = 'inside'",
            ['exitTime' => $exitTime, 'now' => $this->timestamp(), 'id' => $visitorLogId]
        );
    }

    public function revoke(int $visitorLogId, int $revokedBy, string $reason): int
    {
        return $this->connection->execute(
            "UPDATE `visitor_logs`
                SET `status` = 'revoked', `revoked_by` = :by, `revoked_at` = :now,
                    `revoke_reason` = :reason, `updated_at` = :now
              WHERE `visitor_log_id` = :id AND `status` IN ('issued', 'inside')",
            ['by' => $revokedBy, 'now' => $this->timestamp(), 'reason' => mb_substr($reason, 0, 255), 'id' => $visitorLogId]
        );
    }

    /**
     * Close out passes whose validity has elapsed.
     *
     * A pass whose holder is still inside is deliberately left open: expiring
     * it would erase the fact that somebody is on the premises.
     *
     * @return int Number of passes expired.
     */
    public function expireOverdue(): int
    {
        return $this->connection->execute(
            "UPDATE `visitor_logs`
                SET `status` = 'expired', `updated_at` = :now
              WHERE `status` = 'issued' AND `valid_until` < :now",
            ['now' => $this->timestamp()]
        );
    }

    public function countIssuedBetween(string $from, string $to): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `visitor_logs` WHERE `issued_at` BETWEEN ? AND ?',
            [$from, $to]
        );
    }

    public function countInside(): int
    {
        return $this->query()->whereEquals('status', 'inside')->count();
    }

    /**
     * @return array<string,int>
     */
    public function statusCounts(): array
    {
        $rows = $this->connection->select(
            'SELECT `status`, COUNT(*) AS `total` FROM `visitor_logs` GROUP BY `status`'
        );

        $counts = ['issued' => 0, 'inside' => 0, 'completed' => 0, 'expired' => 0, 'revoked' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function nextReference(): string
    {
        $sequence = (int) $this->connection->scalar(
            'SELECT COUNT(*) + 1 FROM `visitor_logs` WHERE DATE(`issued_at`) = ?',
            [now()->format('Y-m-d')]
        );

        return sprintf('VIS-%s-%04d-%s', now()->format('Ymd'), $sequence, \App\Core\Support\Str::randomCode(4));
    }
}
