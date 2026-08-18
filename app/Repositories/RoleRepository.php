<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * Role storage and grant management.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class RoleRepository extends BaseRepository
{
    protected string $table = 'roles';
    protected string $primaryKey = 'role_id';

    protected array $fillable = [
        'role_slug', 'role_name', 'description', 'priority', 'status', 'created_by', 'updated_by',
    ];

    protected array $sortable = ['role_name', 'role_slug', 'priority', 'status', 'created_at'];
    protected array $searchable = ['role_name', 'role_slug', 'description'];

    /**
     * Roles with their user and permission counts, for the listing.
     *
     * @return list<array<string,mixed>>
     */
    public function allWithCounts(): array
    {
        return $this->connection->select(
            'SELECT r.*,
                    (SELECT COUNT(*) FROM `users` u
                      WHERE u.`role_id` = r.`role_id` AND u.`deleted_at` IS NULL) AS `user_count`,
                    (SELECT COUNT(*) FROM `role_permissions` rp
                      WHERE rp.`role_id` = r.`role_id`) AS `permission_count`
               FROM `roles` r
              WHERE r.`deleted_at` IS NULL
              ORDER BY r.`priority`, r.`role_name`'
        );
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

        if (($filters['status'] ?? '') !== '') {
            $query->whereEquals('status', (string) $filters['status']);
        }

        $options['sort']      ??= 'priority';
        $options['direction'] ??= 'ASC';

        return $this->paginateQuery($query, $options);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->findBy('role_slug', $slug);
    }

    /**
     * Permission ids currently granted to a role.
     *
     * @return list<int>
     */
    public function permissionIds(int $roleId): array
    {
        return array_map(intval(...), $this->connection->column(
            'SELECT `permission_id` FROM `role_permissions` WHERE `role_id` = ?',
            [$roleId]
        ));
    }

    /**
     * Permission keys currently granted to a role.
     *
     * @return list<string>
     */
    public function permissionKeys(int $roleId): array
    {
        return array_map(strval(...), $this->connection->column(
            'SELECT p.`permission_key`
               FROM `role_permissions` rp
               INNER JOIN `permissions` p ON p.`permission_id` = rp.`permission_id`
              WHERE rp.`role_id` = ?',
            [$roleId]
        ));
    }

    /**
     * Replace a role's grants with the given set.
     *
     * Runs as a single transaction: a role must never be left with half its
     * permissions, which would silently strip access from everyone holding it.
     *
     * @param list<int> $permissionIds
     *
     * @return array{granted:list<int>,revoked:list<int>}
     */
    public function syncPermissions(int $roleId, array $permissionIds, ?int $grantedBy): array
    {
        $current = $this->permissionIds($roleId);
        $desired = array_values(array_unique(array_map(intval(...), $permissionIds)));

        $toGrant  = array_values(array_diff($desired, $current));
        $toRevoke = array_values(array_diff($current, $desired));

        if ($toGrant === [] && $toRevoke === []) {
            return ['granted' => [], 'revoked' => []];
        }

        $this->connection->transaction(function () use ($roleId, $toGrant, $toRevoke, $grantedBy): void {
            foreach ($toGrant as $permissionId) {
                $this->connection->execute(
                    'INSERT INTO `role_permissions` (`role_id`, `permission_id`, `granted_by`, `granted_at`)
                     VALUES (:role, :permission, :grantedBy, :grantedAt)',
                    [
                        'role'       => $roleId,
                        'permission' => $permissionId,
                        'grantedBy'  => $grantedBy,
                        'grantedAt'  => $this->timestamp(),
                    ]
                );
            }

            foreach (array_chunk($toRevoke, 100) as $chunk) {
                $placeholders = implode(', ', array_fill(0, count($chunk), '?'));

                $this->connection->execute(
                    sprintf(
                        'DELETE FROM `role_permissions` WHERE `role_id` = ? AND `permission_id` IN (%s)',
                        $placeholders
                    ),
                    [$roleId, ...$chunk]
                );
            }
        });

        return ['granted' => $toGrant, 'revoked' => $toRevoke];
    }

    public function userCount(int $roleId): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `users` WHERE `role_id` = ? AND `deleted_at` IS NULL',
            [$roleId]
        );
    }

    /**
     * Members of a role.
     *
     * @return list<array<string,mixed>>
     */
    public function members(int $roleId, int $limit = 100): array
    {
        return (new QueryBuilder($this->connection))
            ->table('v_user_directory')
            ->select(['user_id', 'username', 'full_name', 'email', 'status', 'last_login_at'])
            ->whereEquals('role_id', $roleId)
            ->whereNull('deleted_at')
            ->orderBy('full_name')
            ->limit($limit)
            ->get();
    }

    /**
     * Roles for a drop-down, excluding any above the actor's own authority.
     *
     * A supervisor must not be able to create an administrator, which is
     * precisely the privilege-escalation path this closes.
     *
     * @return list<array<string,mixed>>
     */
    public function assignableBy(int $actorRolePriority): array
    {
        return $this->query()
            ->select(['role_id', 'role_slug', 'role_name', 'description', 'priority'])
            ->whereEquals('status', 'active')
            ->where('priority', '>=', $actorRolePriority)
            ->orderBy('priority')
            ->get();
    }

    public function isSystemRole(int $roleId): bool
    {
        return (int) $this->connection->scalar(
            'SELECT `is_system` FROM `roles` WHERE `role_id` = ?',
            [$roleId]
        ) === 1;
    }
}
