<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * Visitor records.
 *
 * A visitor is retained after their pass closes so a repeat visit reuses one
 * record rather than creating a duplicate person each time.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class VisitorRepository extends BaseRepository
{
    protected string $table = 'visitors';
    protected string $primaryKey = 'visitor_id';

    protected array $fillable = [
        'visitor_code', 'first_name', 'middle_name', 'last_name', 'visitor_type_id',
        'company', 'contact_number', 'email', 'address', 'government_id', 'photo',
        'is_blacklisted', 'blacklist_reason', 'status', 'remarks', 'created_by', 'updated_by',
    ];

    protected array $sortable = ['full_name', 'visitor_code', 'company', 'status', 'created_at'];
    protected array $searchable = ['full_name', 'visitor_code', 'company', 'contact_number', 'email', 'government_id'];

    /**
     * Visitors with their type and visit count.
     */
    public function withDetail(): QueryBuilder
    {
        return (new QueryBuilder($this->connection))
            ->table('visitors', 'v')
            ->select([
                'v.visitor_id', 'v.visitor_code', 'v.full_name', 'v.first_name', 'v.last_name',
                'v.company', 'v.contact_number', 'v.email', 'v.government_id', 'v.photo',
                'v.is_blacklisted', 'v.blacklist_reason', 'v.status', 'v.created_at', 'v.visitor_type_id',
            ])
            ->selectRaw('`vt`.`type_name` AS `visitor_type`')
            ->selectRaw('`vt`.`default_validity_hours` AS `default_validity_hours`')
            ->selectRaw('(SELECT COUNT(*) FROM `visitor_logs` l WHERE l.`visitor_id` = `v`.`visitor_id`) AS `visit_count`')
            ->selectRaw('(SELECT MAX(l.`entry_time`) FROM `visitor_logs` l WHERE l.`visitor_id` = `v`.`visitor_id`) AS `last_visit`')
            ->leftJoin('visitor_types', 'vt.visitor_type_id', 'v.visitor_type_id', 'vt')
            ->whereNull('v.deleted_at');
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->withDetail();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike(
                ['v.full_name', 'v.visitor_code', 'v.company', 'v.contact_number', 'v.government_id'],
                (string) $filters['search']
            );
        }

        foreach (['status' => 'v.status', 'visitor_type_id' => 'v.visitor_type_id'] as $filter => $column) {
            if (($filters[$filter] ?? '') !== '' && ($filters[$filter] ?? null) !== null) {
                $query->whereEquals($column, $filters[$filter]);
            }
        }

        if (($filters['blacklisted'] ?? '') !== '') {
            $query->whereEquals('v.is_blacklisted', (int) (bool) $filters['blacklisted']);
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
        $query->orderBy('v.' . $this->assertSortable((string) ($options['sort'] ?? 'full_name')), (string) ($options['direction'] ?? 'ASC'));

        return Paginator::fromQuery(
            $query,
            max(1, (int) ($options['page'] ?? 1)),
            (int) ($options['per_page'] ?? config('app.pagination.default_per_page', 25))
        );
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findWithDetail(int $visitorId): ?array
    {
        return $this->withDetail()->whereEquals('v.visitor_id', $visitorId)->first();
    }

    /**
     * Find a returning visitor by their identification document.
     *
     * @return array<string,mixed>|null
     */
    public function findByGovernmentId(string $governmentId): ?array
    {
        return $this->findBy('government_id', $governmentId);
    }

    public function setBlacklisted(int $visitorId, bool $blacklisted, ?string $reason, ?int $updatedBy): void
    {
        $this->connection->execute(
            'UPDATE `visitors`
                SET `is_blacklisted` = :flag, `blacklist_reason` = :reason,
                    `updated_by` = :by, `updated_at` = :now
              WHERE `visitor_id` = :id',
            [
                'flag'   => $blacklisted ? 1 : 0,
                'reason' => $blacklisted ? mb_substr((string) $reason, 0, 255) : null,
                'by'     => $updatedBy,
                'now'    => $this->timestamp(),
                'id'     => $visitorId,
            ]
        );
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function selectList(): array
    {
        return $this->query()
            ->select(['visitor_id', 'full_name', 'visitor_code', 'company'])
            ->whereEquals('status', 'active')
            ->whereEquals('is_blacklisted', 0)
            ->orderBy('full_name')
            ->limit(500)
            ->get();
    }

    public function countActive(): int
    {
        return $this->query()->whereEquals('status', 'active')->count();
    }
}
