<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;

/**
 * Organisational departments.
 *
 * A department is referenced by users and by vehicle owners, so it is soft
 * deleted: removing the row would strip the affiliation from records that are
 * still meaningful.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class DepartmentRepository extends BaseRepository
{
    protected string $table = 'departments';
    protected string $primaryKey = 'department_id';

    protected array $fillable = [
        'department_code', 'department_name', 'description', 'contact_number', 'status',
    ];

    protected array $sortable = ['department_code', 'department_name', 'status', 'created_at'];
    protected array $searchable = ['department_code', 'department_name', 'description'];

    /**
     * Departments with the number of people and owners attached to each, which
     * is what makes "can this be retired?" answerable on the listing itself.
     *
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginate(array $filters, array $options): Paginator
    {
        $query = $this->query()
            ->selectRaw('(SELECT COUNT(*) FROM `users` u WHERE u.`department_id` = `departments`.`department_id`'
                . ' AND u.`deleted_at` IS NULL) AS `user_count`')
            ->selectRaw('(SELECT COUNT(*) FROM `vehicle_owners` o WHERE o.`department_id` = `departments`.`department_id`'
                . ' AND o.`deleted_at` IS NULL) AS `owner_count`');

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike($this->searchable, (string) $filters['search']);
        }

        if (($filters['status'] ?? '') !== '') {
            $query->whereEquals('status', (string) $filters['status']);
        }

        $options['sort']      ??= 'department_name';
        $options['direction'] ??= 'ASC';

        return $this->paginateQuery($query, $options);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function selectList(): array
    {
        return $this->query()
            ->select(['department_id', 'department_code', 'department_name'])
            ->whereEquals('status', 'active')
            ->orderBy('department_name', 'ASC')
            ->get();
    }

    /**
     * How many active people would be affected by retiring a department.
     */
    public function memberCount(int $departmentId): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `users` WHERE `department_id` = ? AND `deleted_at` IS NULL',
            [$departmentId]
        );
    }

    /**
     * The next free department code, so the operator never invents one that
     * collides with an existing record.
     */
    public function nextCode(): string
    {
        $highest = (string) $this->connection->scalar(
            "SELECT MAX(`department_code`) FROM `departments` WHERE `department_code` REGEXP '^DEPT-[0-9]+$'"
        );

        $sequence = $highest === '' ? 0 : (int) substr($highest, 5);

        return sprintf('DEPT-%03d', $sequence + 1);
    }
}
