<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;

/**
 * Authentication attempt history.
 *
 * Every attempt is recorded, successful or not, keyed by both the submitted
 * username and the source address. Recording attempts on usernames that do not
 * exist is deliberate: an attacker enumerating accounts leaves a trail.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class LoginAttemptRepository extends BaseRepository
{
    protected string $table = 'login_attempts';
    protected string $primaryKey = 'login_attempt_id';
    protected bool $timestamps = false;
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'username', 'user_id', 'ip_address', 'user_agent', 'successful', 'failure_reason', 'attempted_at',
    ];

    protected array $sortable = ['attempted_at', 'username', 'ip_address', 'successful'];
    protected array $searchable = ['username', 'ip_address', 'failure_reason'];

    /**
     * Record one attempt.
     */
    public function record(
        string $username,
        ?int $userId,
        string $ipAddress,
        string $userAgent,
        bool $successful,
        ?string $failureReason = null
    ): int {
        return $this->create([
            'username'       => mb_substr($username, 0, 50),
            'user_id'        => $userId,
            'ip_address'     => $ipAddress,
            'user_agent'     => mb_substr($userAgent, 0, 255),
            'successful'     => $successful ? 1 : 0,
            'failure_reason' => $failureReason,
            'attempted_at'   => $this->timestamp(),
        ]);
    }

    /**
     * Consecutive failures for a username since its last success.
     *
     * Counting since the last success rather than over a fixed window is what
     * makes the lockout counter behave the way a user expects: signing in
     * successfully clears the slate.
     */
    public function consecutiveFailures(string $username): int
    {
        $lastSuccess = $this->connection->scalar(
            'SELECT MAX(`attempted_at`) FROM `login_attempts` WHERE `username` = ? AND `successful` = 1',
            [$username]
        );

        if ($lastSuccess === null) {
            return (int) $this->connection->scalar(
                'SELECT COUNT(*) FROM `login_attempts` WHERE `username` = ? AND `successful` = 0',
                [$username]
            );
        }

        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `login_attempts`
              WHERE `username` = ? AND `successful` = 0 AND `attempted_at` > ?',
            [$username, $lastSuccess]
        );
    }

    /**
     * Failures from one address inside a window, for per-origin throttling.
     */
    public function failuresFromAddress(string $ipAddress, int $windowMinutes): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `login_attempts`
              WHERE `ip_address` = ? AND `successful` = 0 AND `attempted_at` >= ?',
            [
                $ipAddress,
                now()->modify('-' . max(1, $windowMinutes) . ' minutes')->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Attempt history for one account.
     *
     * @return list<array<string,mixed>>
     */
    public function forUser(int $userId, int $limit = 25): array
    {
        return $this->query()
            ->whereEquals('user_id', $userId)
            ->orderBy('attempted_at', 'DESC')
            ->limit($limit)
            ->get();
    }

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

        if (($filters['successful'] ?? '') !== '') {
            $query->whereEquals('successful', (int) (bool) $filters['successful']);
        }

        $query->whereDateRange('attempted_at', $filters['date_from'] ?? null, $filters['date_to'] ?? null);

        $options['sort']      ??= 'attempted_at';
        $options['direction'] ??= 'DESC';

        return $this->paginateQuery($query, $options);
    }

    /**
     * Addresses producing the most failures, for the security dashboard.
     *
     * @return list<array<string,mixed>>
     */
    public function topFailingAddresses(int $windowHours = 24, int $limit = 10): array
    {
        return $this->connection->select(
            'SELECT `ip_address`, COUNT(*) AS `failures`, COUNT(DISTINCT `username`) AS `usernames_tried`,
                    MAX(`attempted_at`) AS `last_attempt`
               FROM `login_attempts`
              WHERE `successful` = 0 AND `attempted_at` >= :since
              GROUP BY `ip_address`
              ORDER BY `failures` DESC
              LIMIT ' . max(1, $limit),
            ['since' => now()->modify('-' . max(1, $windowHours) . ' hours')->format('Y-m-d H:i:s')]
        );
    }

    public function countFailuresSince(string $since): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `login_attempts` WHERE `successful` = 0 AND `attempted_at` >= ?',
            [$since]
        );
    }
}
