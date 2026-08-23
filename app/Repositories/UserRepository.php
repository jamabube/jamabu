<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * User account storage.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class UserRepository extends BaseRepository
{
    protected string $table = 'users';
    protected string $primaryKey = 'user_id';

    protected array $fillable = [
        'employee_number', 'first_name', 'middle_name', 'last_name', 'suffix', 'gender',
        'birth_date', 'username', 'email', 'mobile_number', 'password_hash',
        'password_changed_at', 'must_change_password', 'role_id', 'department_id',
        'position', 'profile_picture', 'fingerprint_template_id', 'status', 'remarks',
        'created_by', 'updated_by', 'deleted_by',
    ];

    protected array $sortable = [
        'username', 'full_name', 'email', 'employee_number', 'status',
        'last_login_at', 'created_at', 'role_name', 'department_name',
    ];

    protected array $searchable = [
        'username', 'full_name', 'email', 'employee_number', 'mobile_number', 'position',
    ];

    /**
     * Query the directory view, which carries the role and department names the
     * listing needs without every caller repeating the joins.
     */
    private function directory(): QueryBuilder
    {
        return (new QueryBuilder($this->connection))
            ->table('v_user_directory')
            ->whereNull('deleted_at');
    }

    /**
     * Find an active, non-deleted account by username, with its role.
     *
     * Used by authentication, so it deliberately returns the password hash.
     *
     * @return array<string,mixed>|null
     */
    public function findForAuthentication(string $username): ?array
    {
        return $this->connection->selectOne(
            'SELECT u.*, r.`role_name`, r.`role_slug`, r.`status` AS `role_status`, r.`priority` AS `role_priority`,
                    d.`department_name`
               FROM `users` u
               INNER JOIN `roles` r ON r.`role_id` = u.`role_id`
               LEFT JOIN `departments` d ON d.`department_id` = u.`department_id`
              WHERE u.`username` = :username AND u.`deleted_at` IS NULL
              LIMIT 1',
            ['username' => $username]
        );
    }

    /**
     * Reload an account by id for session revalidation on each request.
     *
     * @return array<string,mixed>|null
     */
    public function findWithRole(int $userId): ?array
    {
        return $this->connection->selectOne(
            'SELECT u.*, r.`role_name`, r.`role_slug`, r.`status` AS `role_status`, r.`priority` AS `role_priority`,
                    d.`department_name`
               FROM `users` u
               INNER JOIN `roles` r ON r.`role_id` = u.`role_id`
               LEFT JOIN `departments` d ON d.`department_id` = u.`department_id`
              WHERE u.`user_id` = :id AND u.`deleted_at` IS NULL
              LIMIT 1',
            ['id' => $userId]
        );
    }

    /**
     * The permission keys a user holds through their role.
     *
     * @return list<string>
     */
    public function permissionsFor(int $userId): array
    {
        return array_map(strval(...), $this->connection->column(
            'SELECT p.`permission_key`
               FROM `users` u
               INNER JOIN `role_permissions` rp ON rp.`role_id` = u.`role_id`
               INNER JOIN `permissions` p ON p.`permission_id` = rp.`permission_id`
              WHERE u.`user_id` = :id AND p.`status` = :status',
            ['id' => $userId, 'status' => 'active']
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->findBy('email', $email);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findFromDirectory(int $userId): ?array
    {
        return $this->directory()->whereEquals('user_id', $userId)->first();
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->directory();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike(
                ['username', 'full_name', 'email', 'employee_number', 'mobile_number'],
                (string) $filters['search']
            );
        }

        foreach (['role_id', 'department_id', 'status'] as $column) {
            if (($filters[$column] ?? '') !== '' && ($filters[$column] ?? null) !== null) {
                $query->whereEquals($column, $filters[$column]);
            }
        }

        if (($filters['locked'] ?? '') !== '') {
            $query->whereEquals('is_locked', (int) (bool) $filters['locked']);
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
        $options['sort']      ??= 'full_name';
        $options['direction'] ??= 'ASC';

        $query = $this->filtered($filters);

        $sort = (string) ($options['sort'] ?? '');
        if ($sort !== '') {
            $query->orderBy($this->assertSortable($sort), (string) ($options['direction'] ?? 'ASC'));
        }

        return Paginator::fromQuery(
            $query,
            max(1, (int) ($options['page'] ?? 1)),
            (int) ($options['per_page'] ?? config('app.pagination.default_per_page', 25))
        );
    }

    // ------------------------------------------------------------------
    // Authentication bookkeeping
    // ------------------------------------------------------------------

    /**
     * Record a successful sign-in.
     */
    public function recordSuccessfulLogin(int $userId, string $ipAddress, string $userAgent): void
    {
        $this->connection->execute(
            'UPDATE `users`
                SET `last_login_at` = :at, `last_login_ip` = :ip, `last_login_user_agent` = :agent,
                    `last_activity_at` = :at, `failed_login_attempts` = 0
              WHERE `user_id` = :id',
            [
                'at'    => $this->timestamp(),
                'ip'    => $ipAddress,
                'agent' => mb_substr($userAgent, 0, 255),
                'id'    => $userId,
            ]
        );
    }

    /**
     * Increment the failure counter and return its new value.
     */
    public function incrementFailedAttempts(int $userId): int
    {
        $this->connection->execute(
            'UPDATE `users` SET `failed_login_attempts` = `failed_login_attempts` + 1 WHERE `user_id` = :id',
            ['id' => $userId]
        );

        return (int) $this->connection->scalar(
            'SELECT `failed_login_attempts` FROM `users` WHERE `user_id` = ?',
            [$userId]
        );
    }

    public function resetFailedAttempts(int $userId): void
    {
        $this->connection->execute(
            'UPDATE `users` SET `failed_login_attempts` = 0 WHERE `user_id` = :id',
            ['id' => $userId]
        );
    }

    /**
     * Lock an account, optionally until a moment in time.
     */
    public function lock(int $userId, ?string $until, string $reason): void
    {
        $this->connection->execute(
            'UPDATE `users`
                SET `is_locked` = 1, `locked_until` = :until, `locked_reason` = :reason, `updated_at` = :now
              WHERE `user_id` = :id',
            ['until' => $until, 'reason' => mb_substr($reason, 0, 255), 'now' => $this->timestamp(), 'id' => $userId]
        );
    }

    public function unlock(int $userId): void
    {
        $this->connection->execute(
            'UPDATE `users`
                SET `is_locked` = 0, `locked_until` = NULL, `locked_reason` = NULL,
                    `failed_login_attempts` = 0, `updated_at` = :now
              WHERE `user_id` = :id',
            ['now' => $this->timestamp(), 'id' => $userId]
        );
    }

    public function touchActivity(int $userId): void
    {
        $this->connection->execute(
            'UPDATE `users` SET `last_activity_at` = :now WHERE `user_id` = :id',
            ['now' => $this->timestamp(), 'id' => $userId]
        );
    }

    /**
     * Store a new password hash and stamp the change.
     */
    public function updatePassword(int $userId, string $hash, bool $mustChange = false): void
    {
        $this->connection->execute(
            'UPDATE `users`
                SET `password_hash` = :hash, `password_changed_at` = :now,
                    `must_change_password` = :mustChange, `updated_at` = :now
              WHERE `user_id` = :id',
            ['hash' => $hash, 'now' => $this->timestamp(), 'mustChange' => $mustChange ? 1 : 0, 'id' => $userId]
        );
    }

    // ------------------------------------------------------------------
    // Aggregates
    // ------------------------------------------------------------------

    /**
     * @return array<string,int>
     */
    public function statusCounts(): array
    {
        $rows = $this->connection->select(
            'SELECT `status`, COUNT(*) AS `total` FROM `users` WHERE `deleted_at` IS NULL GROUP BY `status`'
        );

        $counts = ['active' => 0, 'inactive' => 0, 'suspended' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Accounts currently locked out.
     *
     * Distinct from "inactive": a locked account was shut out by the system or
     * an administrator and is waiting to be released, which is an action
     * somebody has to take.
     */
    public function countLocked(): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `users` WHERE `is_locked` = 1 AND `deleted_at` IS NULL'
        );
    }

    /**
     * Active accounts that have not yet replaced the password they were issued.
     *
     * These are the accounts whose password somebody other than the holder has
     * seen, which is why the security audit reports them.
     */
    public function countWhereMustChangePassword(): int
    {
        return (int) $this->connection->scalar(
            "SELECT COUNT(*) FROM `users`
              WHERE `must_change_password` = 1 AND `status` = 'active' AND `deleted_at` IS NULL"
        );
    }

    /**
     * Users holding a given permission, used to address a notification to the
     * people who can actually act on it.
     *
     * @return list<array<string,mixed>>
     */
    public function withPermission(string $permissionKey): array
    {
        return $this->connection->select(
            'SELECT DISTINCT u.`user_id`, u.`username`, u.`email`, u.`full_name`
               FROM `users` u
               INNER JOIN `role_permissions` rp ON rp.`role_id` = u.`role_id`
               INNER JOIN `permissions` p ON p.`permission_id` = rp.`permission_id`
              WHERE u.`status` = :status AND u.`deleted_at` IS NULL
                AND p.`permission_key` IN (:exact, :wildcard, :global)',
            [
                'status'   => 'active',
                'exact'    => $permissionKey,
                'wildcard' => (strstr($permissionKey, '.', true) ?: $permissionKey) . '.*',
                'global'   => '*',
            ]
        );
    }

    /**
     * Active users holding one of the given role slugs.
     *
     * @param list<string> $roleSlugs
     *
     * @return list<array<string,mixed>>
     */
    public function withRoles(array $roleSlugs): array
    {
        if ($roleSlugs === []) {
            return [];
        }

        return (new QueryBuilder($this->connection))
            ->table('users', 'u')
            ->select(['u.user_id', 'u.username', 'u.email', 'u.full_name'])
            ->join('roles', 'r.role_id', 'u.role_id', 'INNER', 'r')
            ->whereIn('r.role_slug', $roleSlugs)
            ->whereEquals('u.status', 'active')
            ->whereNull('u.deleted_at')
            ->get();
    }

    /**
     * Accounts whose password has passed the maximum age.
     *
     * @return list<array<string,mixed>>
     */
    public function withExpiredPasswords(int $maxAgeDays): array
    {
        if ($maxAgeDays <= 0) {
            return [];
        }

        return $this->connection->select(
            'SELECT `user_id`, `username`, `password_changed_at`
               FROM `users`
              WHERE `status` = :status AND `deleted_at` IS NULL
                AND (`password_changed_at` IS NULL OR `password_changed_at` < :cutoff)',
            [
                'status' => 'active',
                'cutoff' => now()->modify('-' . $maxAgeDays . ' days')->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Release locks whose expiry has passed.
     */
    public function releaseExpiredLocks(): int
    {
        return $this->connection->execute(
            'UPDATE `users`
                SET `is_locked` = 0, `locked_until` = NULL, `locked_reason` = NULL, `failed_login_attempts` = 0
              WHERE `is_locked` = 1 AND `locked_until` IS NOT NULL AND `locked_until` <= ?',
            [$this->timestamp()]
        );
    }

    /**
     * How many active administrators exist.
     *
     * Guards the rule that the last administrator cannot be removed, demoted
     * or locked out — an unrecoverable system is worse than any other failure.
     */
    public function countActiveAdministrators(): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*)
               FROM `users` u
               INNER JOIN `roles` r ON r.`role_id` = u.`role_id`
              WHERE r.`role_slug` = :slug AND u.`status` = :status
                AND u.`is_locked` = 0 AND u.`deleted_at` IS NULL',
            ['slug' => 'administrator', 'status' => 'active']
        );
    }

    public function isAdministrator(int $userId): bool
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*)
               FROM `users` u
               INNER JOIN `roles` r ON r.`role_id` = u.`role_id`
              WHERE u.`user_id` = :id AND r.`role_slug` = :slug',
            ['id' => $userId, 'slug' => 'administrator']
        ) > 0;
    }

    /**
     * Generate the next employee number in the configured series.
     */
    public function nextEmployeeNumber(string $prefix = 'EMP'): string
    {
        $highest = (string) $this->connection->scalar(
            'SELECT `employee_number` FROM `users`
              WHERE `employee_number` LIKE :prefix
              ORDER BY LENGTH(`employee_number`) DESC, `employee_number` DESC
              LIMIT 1',
            ['prefix' => $prefix . '-%']
        );

        $sequence = $highest === '' ? 0 : (int) substr($highest, strlen($prefix) + 1);

        return sprintf('%s-%04d', $prefix, $sequence + 1);
    }
}
