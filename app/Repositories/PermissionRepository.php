<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Permission catalogue.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class PermissionRepository extends BaseRepository
{
    protected string $table = 'permissions';
    protected string $primaryKey = 'permission_id';
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'module_id', 'module_key', 'permission_key', 'permission_name',
        'description', 'is_dangerous', 'sort_order', 'status',
    ];

    protected array $sortable = ['permission_key', 'module_key', 'permission_name', 'sort_order'];
    protected array $searchable = ['permission_key', 'permission_name', 'description', 'module_key'];

    /**
     * Every active permission grouped by module, which is exactly the shape the
     * permission matrix renders.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public function groupedByModule(): array
    {
        $rows = $this->connection->select(
            'SELECT p.*, m.`module_name`, m.`icon`
               FROM `permissions` p
               LEFT JOIN `system_modules` m ON m.`module_id` = p.`module_id`
              WHERE p.`status` = :status
              ORDER BY COALESCE(m.`sort_order`, 999), p.`sort_order`',
            ['status' => 'active']
        );

        $grouped = [];

        foreach ($rows as $row) {
            $grouped[(string) $row['module_key']][] = $row;
        }

        return $grouped;
    }

    /**
     * Map permission keys onto their ids.
     *
     * @param list<string> $keys
     *
     * @return list<int>
     */
    public function idsForKeys(array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($keys), '?'));

        return array_map(intval(...), $this->connection->column(
            sprintf('SELECT `permission_id` FROM `permissions` WHERE `permission_key` IN (%s)', $placeholders),
            $keys
        ));
    }

    /**
     * Every permission key that exists.
     *
     * Used by the security-audit command to confirm that no route declares a
     * permission the catalogue does not define — a typo there would silently
     * lock a module out for everyone.
     *
     * @return list<string>
     */
    public function allKeys(): array
    {
        return array_map(strval(...), $this->connection->column(
            'SELECT `permission_key` FROM `permissions` ORDER BY `permission_key`'
        ));
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByKey(string $key): ?array
    {
        return $this->findBy('permission_key', $key);
    }

    /**
     * How many roles hold each permission, shown beside the catalogue.
     *
     * @return array<string,int>
     */
    public function roleCounts(): array
    {
        $rows = $this->connection->select(
            'SELECT p.`permission_key`, COUNT(rp.`role_id`) AS `roles`
               FROM `permissions` p
               LEFT JOIN `role_permissions` rp ON rp.`permission_id` = p.`permission_id`
              GROUP BY p.`permission_key`'
        );

        $counts = [];

        foreach ($rows as $row) {
            $counts[(string) $row['permission_key']] = (int) $row['roles'];
        }

        return $counts;
    }
}
