<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\QueryBuilder;

/**
 * Guardhouse operator shifts.
 *
 * A guard authenticates at a station with a fingerprint before monitoring
 * begins; this table records who is accountable for the transactions recorded
 * there during a given window. That accountability is precisely what the manual
 * logbook could not provide.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class OperatorSessionRepository extends BaseRepository
{
    protected string $table = 'operator_sessions';
    protected string $primaryKey = 'operator_session_id';
    protected bool $timestamps = false;
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'device_id', 'user_id', 'template_id', 'authenticated_at', 'expires_at',
        'last_activity_at', 'match_score', 'status',
    ];

    protected array $sortable = ['authenticated_at', 'expires_at', 'status'];

    /**
     * The operator currently on duty at a station.
     *
     * @return array<string,mixed>|null
     */
    public function activeForDevice(int $deviceId): ?array
    {
        return $this->connection->selectOne(
            "SELECT s.*, u.`full_name`, u.`username`, r.`role_name`
               FROM `operator_sessions` s
               INNER JOIN `users` u ON u.`user_id` = s.`user_id`
               INNER JOIN `roles` r ON r.`role_id` = u.`role_id`
              WHERE s.`device_id` = :device AND s.`status` = 'active' AND s.`expires_at` > :now
              ORDER BY s.`authenticated_at` DESC
              LIMIT 1",
            ['device' => $deviceId, 'now' => $this->timestamp()]
        );
    }

    /**
     * Open a shift, superseding any earlier one at the same station.
     *
     * Only one operator can be accountable at a station at a time, so opening a
     * shift necessarily closes the previous one.
     */
    public function open(int $deviceId, int $userId, ?int $templateId, int $minutes, ?int $matchScore): int
    {
        return $this->connection->transaction(function () use ($deviceId, $userId, $templateId, $minutes, $matchScore): int {
            $this->connection->execute(
                "UPDATE `operator_sessions`
                    SET `status` = 'ended', `ended_at` = :now, `end_reason` = 'superseded'
                  WHERE `device_id` = :device AND `status` = 'active'",
                ['now' => $this->timestamp(), 'device' => $deviceId]
            );

            return $this->create([
                'device_id'        => $deviceId,
                'user_id'          => $userId,
                'template_id'      => $templateId,
                'authenticated_at' => $this->timestamp(),
                'expires_at'       => now()->modify('+' . max(1, $minutes) . ' minutes')->format('Y-m-d H:i:s'),
                'last_activity_at' => $this->timestamp(),
                'match_score'      => $matchScore,
                'status'           => 'active',
            ]);
        });
    }

    /**
     * Extend the shift and count the transaction it just covered.
     */
    public function recordTransaction(int $operatorSessionId, int $minutes): void
    {
        $this->connection->execute(
            'UPDATE `operator_sessions`
                SET `transaction_count` = `transaction_count` + 1,
                    `last_activity_at` = :now,
                    `expires_at` = :expires
              WHERE `operator_session_id` = :id',
            [
                'now'     => $this->timestamp(),
                'expires' => now()->modify('+' . max(1, $minutes) . ' minutes')->format('Y-m-d H:i:s'),
                'id'      => $operatorSessionId,
            ]
        );
    }

    public function close(int $operatorSessionId, string $reason): int
    {
        return $this->connection->execute(
            "UPDATE `operator_sessions`
                SET `status` = 'ended', `ended_at` = :now, `end_reason` = :reason
              WHERE `operator_session_id` = :id AND `status` = 'active'",
            ['now' => $this->timestamp(), 'reason' => $reason, 'id' => $operatorSessionId]
        );
    }

    /**
     * Close every open shift at a station, used when a device restarts.
     */
    public function closeAllForDevice(int $deviceId, string $reason): int
    {
        return $this->connection->execute(
            "UPDATE `operator_sessions`
                SET `status` = 'ended', `ended_at` = :now, `end_reason` = :reason
              WHERE `device_id` = :device AND `status` = 'active'",
            ['now' => $this->timestamp(), 'reason' => $reason, 'device' => $deviceId]
        );
    }

    /**
     * End shifts whose window has elapsed.
     */
    public function expireOverdue(): int
    {
        return $this->connection->execute(
            "UPDATE `operator_sessions`
                SET `status` = 'ended', `ended_at` = :now, `end_reason` = 'expired'
              WHERE `status` = 'active' AND `expires_at` <= :now",
            ['now' => $this->timestamp()]
        );
    }

    /**
     * Shift history, for the accountability report.
     *
     * @param array<string,mixed> $filters
     *
     * @return list<array<string,mixed>>
     */
    public function history(array $filters, int $limit = 100): array
    {
        $query = (new QueryBuilder($this->connection))
            ->table('operator_sessions', 's')
            ->select([
                's.operator_session_id', 's.authenticated_at', 's.expires_at', 's.ended_at',
                's.end_reason', 's.transaction_count', 's.match_score', 's.status',
                's.device_id', 's.user_id',
            ])
            ->selectRaw('`u`.`full_name` AS `operator_name`')
            ->selectRaw('`dv`.`device_name` AS `device_name`')
            ->leftJoin('users', 'u.user_id', 's.user_id', 'u')
            ->leftJoin('devices', 'dv.device_id', 's.device_id', 'dv');

        foreach (['device_id' => 's.device_id', 'user_id' => 's.user_id', 'status' => 's.status'] as $filter => $column) {
            if (($filters[$filter] ?? '') !== '' && ($filters[$filter] ?? null) !== null) {
                $query->whereEquals($column, $filters[$filter]);
            }
        }

        $query->whereDateRange('s.authenticated_at', $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        return $query->orderBy('s.authenticated_at', 'DESC')->limit($limit)->get();
    }

    public function countActive(): int
    {
        return (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM `operator_sessions` WHERE `status` = 'active' AND `expires_at` > ?",
            [$this->timestamp()]
        );
    }
}
