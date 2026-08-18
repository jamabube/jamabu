<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * Registered vehicle owners.
 *
 * Owners do not use the application; they exist so every vehicle has an
 * accountable person behind it.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class VehicleOwnerRepository extends BaseRepository
{
    protected string $table = 'vehicle_owners';
    protected string $primaryKey = 'owner_id';

    protected array $fillable = [
        'owner_code', 'first_name', 'middle_name', 'last_name', 'suffix', 'owner_category',
        'company', 'address', 'contact_number', 'email', 'government_id', 'user_id',
        'department_id', 'status', 'remarks', 'created_by', 'updated_by',
    ];

    protected array $sortable = ['full_name', 'owner_code', 'owner_category', 'company', 'status', 'created_at'];
    protected array $searchable = ['full_name', 'owner_code', 'company', 'contact_number', 'email', 'government_id'];

    /**
     * Owners with a count of the vehicles registered to them.
     */
    public function withVehicleCounts(): QueryBuilder
    {
        return (new QueryBuilder($this->connection))
            ->table('vehicle_owners', 'o')
            ->select([
                'o.owner_id', 'o.owner_code', 'o.full_name', 'o.first_name', 'o.last_name',
                'o.owner_category', 'o.company', 'o.address', 'o.contact_number', 'o.email',
                'o.status', 'o.created_at', 'o.department_id',
            ])
            ->selectRaw('(SELECT COUNT(*) FROM `vehicles` v WHERE v.`owner_id` = `o`.`owner_id` AND v.`deleted_at` IS NULL) AS `vehicle_count`')
            ->selectRaw('`dp`.`department_name` AS `department_name`')
            ->leftJoin('departments', 'dp.department_id', 'o.department_id', 'dp')
            ->whereNull('o.deleted_at');
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->withVehicleCounts();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike(
                ['o.full_name', 'o.owner_code', 'o.company', 'o.contact_number', 'o.email'],
                (string) $filters['search']
            );
        }

        foreach (['status' => 'o.status', 'owner_category' => 'o.owner_category',
                  'department_id' => 'o.department_id'] as $filter => $column) {
            if (($filters[$filter] ?? '') !== '' && ($filters[$filter] ?? null) !== null) {
                $query->whereEquals($column, $filters[$filter]);
            }
        }

        return $query;
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginate(array $filters, array $options): Paginator
    {
        $query = $this->filtered($filters);
        $query->orderBy('o.' . $this->assertSortable((string) ($options['sort'] ?? 'full_name')), (string) ($options['direction'] ?? 'ASC'));

        return Paginator::fromQuery(
            $query,
            max(1, (int) ($options['page'] ?? 1)),
            (int) ($options['per_page'] ?? config('app.pagination.default_per_page', 25))
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findWithDetail(int $ownerId): ?array
    {
        return $this->withVehicleCounts()->whereEquals('o.owner_id', $ownerId)->first();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function selectList(): array
    {
        return $this->query()
            ->select(['owner_id', 'full_name', 'owner_code', 'company'])
            ->whereEquals('status', 'active')
            ->orderBy('full_name')
            ->get();
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function vehicles(int $ownerId): array
    {
        return (new QueryBuilder($this->connection))
            ->table('v_vehicle_directory')
            ->whereEquals('owner_id', $ownerId)
            ->whereNull('deleted_at')
            ->orderBy('plate_number')
            ->get();
    }

    public function nextCode(): string
    {
        $highest = (string) $this->connection->scalar(
            "SELECT `owner_code` FROM `vehicle_owners`
              WHERE `owner_code` LIKE 'OWN-%'
              ORDER BY LENGTH(`owner_code`) DESC, `owner_code` DESC
              LIMIT 1"
        );

        $sequence = $highest === '' ? 0 : (int) substr($highest, 4);

        return sprintf('OWN-%04d', $sequence + 1);
    }
}
