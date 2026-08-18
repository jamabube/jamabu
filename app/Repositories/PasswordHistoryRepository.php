<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Security\Hasher;

/**
 * Previous password hashes, supporting the reuse-restriction policy.
 *
 * Only hashes are kept, and the list is trimmed to the configured depth so the
 * table cannot grow without bound.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class PasswordHistoryRepository extends BaseRepository
{
    protected string $table = 'password_history';
    protected string $primaryKey = 'password_history_id';
    protected bool $timestamps = false;
    protected ?string $softDeleteColumn = null;

    protected array $fillable = ['user_id', 'password_hash', 'changed_by', 'created_at'];

    /**
     * Record a hash and prune anything beyond the retained depth.
     */
    public function record(int $userId, string $passwordHash, ?int $changedBy, int $depth): void
    {
        $this->create([
            'user_id'       => $userId,
            'password_hash' => $passwordHash,
            'changed_by'    => $changedBy,
            'created_at'    => $this->timestamp(),
        ]);

        $this->prune($userId, $depth);
    }

    /**
     * Whether a candidate password matches any retained hash.
     *
     * Each stored hash has to be verified individually — bcrypt hashes are
     * salted, so the same password produces a different hash every time and
     * cannot be compared by equality.
     */
    public function matchesRecent(int $userId, string $candidate, int $depth): bool
    {
        if ($depth <= 0) {
            return false;
        }

        $hashes = $this->connection->column(
            'SELECT `password_hash` FROM `password_history`
              WHERE `user_id` = ?
              ORDER BY `created_at` DESC
              LIMIT ' . max(1, $depth),
            [$userId]
        );

        foreach ($hashes as $hash) {
            if (Hasher::verify($candidate, (string) $hash)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Keep only the most recent $depth entries for a user.
     */
    public function prune(int $userId, int $depth): int
    {
        if ($depth <= 0) {
            return $this->connection->execute(
                'DELETE FROM `password_history` WHERE `user_id` = ?',
                [$userId]
            );
        }

        // A sub-select over the same table needs a derived-table wrapper in
        // MySQL, which will not read from the table it is deleting from.
        return $this->connection->execute(
            'DELETE FROM `password_history`
              WHERE `user_id` = :user
                AND `password_history_id` NOT IN (
                    SELECT `id` FROM (
                        SELECT `password_history_id` AS `id`
                          FROM `password_history`
                         WHERE `user_id` = :user2
                         ORDER BY `created_at` DESC
                         LIMIT ' . max(1, $depth) . '
                    ) AS `retained`
                )',
            ['user' => $userId, 'user2' => $userId]
        );
    }

    public function countFor(int $userId): int
    {
        return $this->countWhere('user_id', $userId);
    }
}
