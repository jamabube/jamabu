<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;
use App\Core\Support\Str;

/**
 * Reusable temporary visitor cards.
 *
 * A card is a physical asset with its own lifecycle; who currently holds it is
 * derived from the open row in visitor_logs rather than duplicated here, so the
 * two can never disagree.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class RfidCardRepository extends BaseRepository
{
    protected string $table = 'rfid_cards';
    protected string $primaryKey = 'rfid_card_id';

    protected array $fillable = [
        'card_uid', 'card_code', 'card_type', 'status', 'remarks', 'created_by', 'updated_by',
    ];

    protected array $sortable = ['card_code', 'card_uid', 'status', 'issued_count', 'last_issued_at', 'created_at'];
    protected array $searchable = ['card_uid', 'card_code'];

    /**
     * Cards with their current holder, if any.
     */
    public function withHolder(): QueryBuilder
    {
        return (new QueryBuilder($this->connection))
            ->table('rfid_cards', 'c')
            ->select([
                'c.rfid_card_id', 'c.card_uid', 'c.card_code', 'c.card_type', 'c.status',
                'c.issued_count', 'c.last_issued_at', 'c.last_scanned_at', 'c.remarks', 'c.created_at',
            ])
            ->selectRaw('`vl`.`visitor_log_id` AS `visitor_log_id`')
            ->selectRaw('`vl`.`pass_reference` AS `pass_reference`')
            ->selectRaw('`vl`.`valid_until` AS `valid_until`')
            ->selectRaw('`vi`.`full_name` AS `visitor_name`')
            // Only an open pass counts as current possession.
            ->leftJoin('visitor_logs', 'vl.rfid_card_id', 'c.rfid_card_id', 'vl')
            ->leftJoin('visitors', 'vi.visitor_id', 'vl.visitor_id', 'vi')
            ->whereNull('c.deleted_at')
            ->whereRaw("`vl`.`visitor_log_id` IS NULL OR `vl`.`status` IN ('issued', 'inside')");
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByUid(string $uid): ?array
    {
        return $this->findBy('card_uid', Str::normaliseUid($uid));
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->withHolder();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike(['c.card_uid', 'c.card_code', 'vi.full_name'], (string) $filters['search']);
        }

        if (($filters['status'] ?? '') !== '') {
            $query->whereEquals('c.status', (string) $filters['status']);
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
        $query->orderBy('c.' . $this->assertSortable((string) ($options['sort'] ?? 'card_code')), (string) ($options['direction'] ?? 'ASC'));

        return Paginator::fromQuery(
            $query,
            max(1, (int) ($options['page'] ?? 1)),
            (int) ($options['per_page'] ?? config('app.pagination.default_per_page', 25))
        );
    }

    /**
     * Cards free to issue right now.
     *
     * @return list<array<string,mixed>>
     */
    public function available(): array
    {
        return $this->query()
            ->select(['rfid_card_id', 'card_code', 'card_uid'])
            ->whereEquals('status', 'available')
            ->orderBy('card_code')
            ->get();
    }

    public function markIssued(int $cardId): void
    {
        $this->connection->execute(
            "UPDATE `rfid_cards`
                SET `status` = 'issued', `issued_count` = `issued_count` + 1,
                    `last_issued_at` = :now, `updated_at` = :now
              WHERE `rfid_card_id` = :id",
            ['now' => $this->timestamp(), 'id' => $cardId]
        );
    }

    /**
     * Return a card to the pool once its pass closes.
     */
    public function markReturned(int $cardId): void
    {
        $this->connection->execute(
            "UPDATE `rfid_cards`
                SET `status` = 'available', `updated_at` = :now
              WHERE `rfid_card_id` = :id AND `status` = 'issued'",
            ['now' => $this->timestamp(), 'id' => $cardId]
        );
    }

    public function recordScan(int $cardId): void
    {
        $this->connection->execute(
            'UPDATE `rfid_cards` SET `last_scanned_at` = :now WHERE `rfid_card_id` = :id',
            ['now' => $this->timestamp(), 'id' => $cardId]
        );
    }

    /**
     * @return array<string,int>
     */
    public function statusCounts(): array
    {
        $rows = $this->connection->select(
            'SELECT `status`, COUNT(*) AS `total` FROM `rfid_cards` WHERE `deleted_at` IS NULL GROUP BY `status`'
        );

        $counts = ['available' => 0, 'issued' => 0, 'inactive' => 0, 'lost' => 0, 'damaged' => 0, 'retired' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['status']] = (int) $row['total'];
        }

        return $counts;
    }

    public function nextCode(): string
    {
        /*
         * Only codes that are the prefix followed by digits count.
         * A hand-entered code such as "VC-TEST01" is not part of the
         * sequence, and ordering by length would otherwise pick it as
         * the highest, read its sequence as zero, and hand back a code
         * that already exists.
         */
        $highest = (int) $this->connection->scalar(
            "SELECT MAX(CAST(SUBSTRING(`card_code`, 4) AS UNSIGNED))
               FROM `rfid_cards`
              WHERE `card_code` REGEXP '^VC-[0-9]+$'"
        );

        $sequence = $highest;

        return sprintf('VC-%03d', $sequence + 1);
    }
}
