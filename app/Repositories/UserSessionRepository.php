<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Security\Hasher;

/**
 * Server-side session register.
 *
 * The raw PHP session identifier is never stored; only its SHA-256 hash is, so
 * a leaked database row cannot be turned into a usable session cookie.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class UserSessionRepository extends BaseRepository
{
    protected string $table = 'user_sessions';
    protected string $primaryKey = 'user_session_id';
    protected bool $timestamps = false;
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'user_id', 'session_key', 'ip_address', 'user_agent', 'device_label',
        'fingerprint', 'login_at', 'last_activity_at', 'expires_at', 'status',
    ];

    protected array $sortable = ['login_at', 'last_activity_at', 'status'];

    /**
     * Register a new session, replacing any stale row with the same key.
     */
    public function open(
        int $userId,
        string $sessionId,
        string $ipAddress,
        string $userAgent,
        string $deviceLabel,
        string $fingerprint,
        string $expiresAt
    ): int {
        $key = Hasher::hashToken($sessionId);
        $now = $this->timestamp();

        // PHP can reuse an identifier after a regeneration cycle, so an upsert
        // is safer than a plain insert against the unique index.
        $this->connection->execute(
            'INSERT INTO `user_sessions`
                (`user_id`, `session_key`, `ip_address`, `user_agent`, `device_label`, `fingerprint`,
                 `login_at`, `last_activity_at`, `expires_at`, `status`)
             VALUES (:user, :key, :ip, :agent, :label, :fingerprint, :now, :now, :expires, :status)
             ON DUPLICATE KEY UPDATE
                `user_id` = VALUES(`user_id`),
                `ip_address` = VALUES(`ip_address`),
                `user_agent` = VALUES(`user_agent`),
                `device_label` = VALUES(`device_label`),
                `fingerprint` = VALUES(`fingerprint`),
                `login_at` = VALUES(`login_at`),
                `last_activity_at` = VALUES(`last_activity_at`),
                `expires_at` = VALUES(`expires_at`),
                `logout_at` = NULL,
                `termination_reason` = NULL,
                `status` = VALUES(`status`)',
            [
                'user'        => $userId,
                'key'         => $key,
                'ip'          => $ipAddress,
                'agent'       => mb_substr($userAgent, 0, 255),
                'label'       => mb_substr($deviceLabel, 0, 120),
                'fingerprint' => $fingerprint,
                'now'         => $now,
                'expires'     => $expiresAt,
                'status'      => 'active',
            ]
        );

        return (int) $this->connection->scalar(
            'SELECT `user_session_id` FROM `user_sessions` WHERE `session_key` = ?',
            [$key]
        );
    }

    /**
     * Move an active record onto a new identifier after regeneration.
     */
    public function rekey(string $oldSessionId, string $newSessionId): void
    {
        $this->connection->execute(
            'UPDATE `user_sessions`
                SET `session_key` = :new, `last_activity_at` = :now
              WHERE `session_key` = :old AND `status` = :status',
            [
                'new'    => Hasher::hashToken($newSessionId),
                'now'    => $this->timestamp(),
                'old'    => Hasher::hashToken($oldSessionId),
                'status' => 'active',
            ]
        );
    }

    public function touch(string $sessionId, string $expiresAt): void
    {
        $this->connection->execute(
            'UPDATE `user_sessions`
                SET `last_activity_at` = :now, `expires_at` = :expires
              WHERE `session_key` = :key AND `status` = :status',
            [
                'now'     => $this->timestamp(),
                'expires' => $expiresAt,
                'key'     => Hasher::hashToken($sessionId),
                'status'  => 'active',
            ]
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findBySessionId(string $sessionId): ?array
    {
        return $this->findBy('session_key', Hasher::hashToken($sessionId));
    }

    /**
     * Whether a session is still registered as active.
     *
     * A session cookie whose row has been terminated must stop working
     * immediately; this is what makes "force logout" take effect.
     */
    public function isActive(string $sessionId): bool
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `user_sessions` WHERE `session_key` = ? AND `status` = ?',
            [Hasher::hashToken($sessionId), 'active']
        ) > 0;
    }

    /**
     * End one session.
     */
    public function close(string $sessionId, string $reason, ?int $terminatedBy = null): int
    {
        return $this->connection->execute(
            'UPDATE `user_sessions`
                SET `status` = :ended, `logout_at` = :now, `termination_reason` = :reason,
                    `terminated_by` = :by
              WHERE `session_key` = :key AND `status` = :active',
            [
                'ended'  => 'ended',
                'now'    => $this->timestamp(),
                'reason' => $reason,
                'by'     => $terminatedBy,
                'key'    => Hasher::hashToken($sessionId),
                'active' => 'active',
            ]
        );
    }

    public function closeById(int $sessionRowId, string $reason, ?int $terminatedBy = null): int
    {
        return $this->connection->execute(
            'UPDATE `user_sessions`
                SET `status` = :ended, `logout_at` = :now, `termination_reason` = :reason, `terminated_by` = :by
              WHERE `user_session_id` = :id AND `status` = :active',
            [
                'ended'  => 'ended',
                'now'    => $this->timestamp(),
                'reason' => $reason,
                'by'     => $terminatedBy,
                'id'     => $sessionRowId,
                'active' => 'active',
            ]
        );
    }

    /**
     * End every session for a user, optionally sparing the current one.
     */
    public function closeAllFor(int $userId, string $reason, ?string $exceptSessionId = null, ?int $terminatedBy = null): int
    {
        $sql = 'UPDATE `user_sessions`
                   SET `status` = :ended, `logout_at` = :now, `termination_reason` = :reason, `terminated_by` = :by
                 WHERE `user_id` = :user AND `status` = :active';

        $bindings = [
            'ended'  => 'ended',
            'now'    => $this->timestamp(),
            'reason' => $reason,
            'by'     => $terminatedBy,
            'user'   => $userId,
            'active' => 'active',
        ];

        if ($exceptSessionId !== null) {
            $sql .= ' AND `session_key` <> :except';
            $bindings['except'] = Hasher::hashToken($exceptSessionId);
        }

        return $this->connection->execute($sql, $bindings);
    }

    /**
     * Active sessions for a user, newest first.
     *
     * @return list<array<string,mixed>>
     */
    public function activeFor(int $userId): array
    {
        return $this->query()
            ->whereEquals('user_id', $userId)
            ->whereEquals('status', 'active')
            ->orderBy('last_activity_at', 'DESC')
            ->get();
    }

    /**
     * Every active session across the system, for the administration panel.
     *
     * @return list<array<string,mixed>>
     */
    public function allActive(int $limit = 200): array
    {
        return $this->connection->select(
            'SELECT s.*, u.`username`, u.`full_name`, r.`role_name`
               FROM `user_sessions` s
               INNER JOIN `users` u ON u.`user_id` = s.`user_id`
               INNER JOIN `roles` r ON r.`role_id` = u.`role_id`
              WHERE s.`status` = :status
              ORDER BY s.`last_activity_at` DESC
              LIMIT ' . max(1, $limit),
            ['status' => 'active']
        );
    }

    /**
     * Sign-in history for a user's profile page.
     *
     * @return list<array<string,mixed>>
     */
    public function historyFor(int $userId, int $limit = 25): array
    {
        return $this->query()
            ->whereEquals('user_id', $userId)
            ->orderBy('login_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    public function countActive(): int
    {
        return $this->query()->whereEquals('status', 'active')->count();
    }

    public function countActiveFor(int $userId): int
    {
        return $this->query()->whereEquals('user_id', $userId)->whereEquals('status', 'active')->count();
    }

    /**
     * Close sessions whose expiry has passed.
     *
     * Run by the maintenance task, so the active-session list reflects reality
     * even for users who simply closed the browser.
     */
    public function closeExpired(): int
    {
        return $this->connection->execute(
            'UPDATE `user_sessions`
                SET `status` = :ended, `logout_at` = :now, `termination_reason` = :reason
              WHERE `status` = :active AND `expires_at` IS NOT NULL AND `expires_at` < :now',
            ['ended' => 'ended', 'now' => $this->timestamp(), 'reason' => 'timeout', 'active' => 'active']
        );
    }
}
