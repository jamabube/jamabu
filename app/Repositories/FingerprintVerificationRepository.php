<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * History of every fingerprint verification attempt.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class FingerprintVerificationRepository extends BaseRepository
{
    protected string $table = 'fingerprint_verifications';
    protected string $primaryKey = 'verification_id';
    protected bool $timestamps = false;
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'device_id', 'template_id', 'user_id', 'driver_id', 'sensor_slot',
        'purpose', 'successful', 'match_score', 'failure_reason', 'verified_at',
    ];

    protected array $sortable = ['verified_at', 'successful', 'purpose', 'match_score'];

    /**
     * Attempts with the person and station involved.
     */
    public function withContext(): QueryBuilder
    {
        return (new QueryBuilder($this->connection))
            ->table('fingerprint_verifications', 'v')
            ->select([
                'v.verification_id', 'v.sensor_slot', 'v.purpose', 'v.successful',
                'v.match_score', 'v.failure_reason', 'v.verified_at',
                'v.template_id', 'v.device_id', 'v.user_id',
            ])
            ->selectRaw('`u`.`full_name` AS `user_name`')
            ->selectRaw('`dr`.`full_name` AS `driver_name`')
            ->selectRaw('`dv`.`device_name` AS `device_name`')
            ->selectRaw('`f`.`template_number` AS `template_number`')
            ->leftJoin('users', 'u.user_id', 'v.user_id', 'u')
            ->leftJoin('drivers', 'dr.driver_id', 'v.driver_id', 'dr')
            ->leftJoin('devices', 'dv.device_id', 'v.device_id', 'dv')
            ->leftJoin('fingerprint_templates', 'f.template_id', 'v.template_id', 'f');
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginate(array $filters, array $options): Paginator
    {
        $query = $this->withContext();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike(['u.full_name', 'dr.full_name', 'f.template_number'], (string) $filters['search']);
        }

        foreach (['device_id' => 'v.device_id', 'template_id' => 'v.template_id',
                  'user_id' => 'v.user_id', 'purpose' => 'v.purpose'] as $filter => $column) {
            if (($filters[$filter] ?? '') !== '' && ($filters[$filter] ?? null) !== null) {
                $query->whereEquals($column, $filters[$filter]);
            }
        }

        if (($filters['successful'] ?? '') !== '') {
            $query->whereEquals('v.successful', (int) (bool) $filters['successful']);
        }

        $query->whereDateRange('v.verified_at', $filters['date_from'] ?? null, $filters['date_to'] ?? null);
        $query->orderBy('v.' . $this->assertSortable((string) ($options['sort'] ?? 'verified_at')), (string) ($options['direction'] ?? 'DESC'));

        return Paginator::fromQuery(
            $query,
            max(1, (int) ($options['page'] ?? 1)),
            (int) ($options['per_page'] ?? config('app.pagination.default_per_page', 25))
        );
    }

    /**
     * Recent attempts for one enrolment.
     *
     * @return list<array<string,mixed>>
     */
    public function forTemplate(int $templateId, int $limit = 25): array
    {
        return $this->withContext()
            ->whereEquals('v.template_id', $templateId)
            ->orderBy('v.verified_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Consecutive failures at one station inside a window.
     *
     * Repeated failures at a single gate are the pattern that distinguishes a
     * dirty sensor from someone trying fingers that are not enrolled.
     */
    public function recentFailuresAtDevice(int $deviceId, int $withinSeconds): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `fingerprint_verifications`
              WHERE `device_id` = ? AND `successful` = 0 AND `verified_at` >= ?',
            [
                $deviceId,
                now()->modify('-' . max(1, $withinSeconds) . ' seconds')->format('Y-m-d H:i:s'),
            ]
        );
    }

    public function countFailuresBetween(string $from, string $to): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `fingerprint_verifications`
              WHERE `successful` = 0 AND `verified_at` BETWEEN ? AND ?',
            [$from, $to]
        );
    }
}
