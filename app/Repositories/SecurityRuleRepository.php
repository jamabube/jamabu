<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Configurable security thresholds.
 *
 * Each row is one enforceable rule — how many failures, in how long a window,
 * and what the system does about it. The rows are the authority: they are
 * overlaid onto the configuration tree at the start of every request, so
 * enforcement code keeps reading config() and never learns this table exists.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class SecurityRuleRepository extends BaseRepository
{
    protected string $table = 'security_rules';
    protected string $primaryKey = 'security_rule_id';

    protected array $fillable = [
        'rule_key', 'rule_name', 'description', 'threshold_value',
        'window_seconds', 'action', 'severity', 'is_enabled', 'updated_by',
    ];

    protected array $sortable = ['rule_key', 'rule_name', 'severity', 'action', 'is_enabled'];
    protected array $searchable = ['rule_key', 'rule_name', 'description'];

    /**
     * Every rule, in the order the administration screen shows them.
     *
     * @return list<array<string,mixed>>
     */
    public function all(?string $orderBy = null, string $direction = 'ASC'): array
    {
        return $this->query()
            ->orderBy($orderBy ?? 'rule_name', $direction)
            ->get();
    }

    /**
     * The enabled rules keyed by their key, for the configuration overlay.
     *
     * @return array<string,array{threshold:int,window:int,action:string,severity:string}>
     */
    public function enabledKeyed(): array
    {
        $rows = $this->connection->select(
            'SELECT `rule_key`, `threshold_value`, `window_seconds`, `action`, `severity`
               FROM `security_rules`
              WHERE `is_enabled` = 1'
        );

        $keyed = [];

        foreach ($rows as $row) {
            $keyed[(string) $row['rule_key']] = [
                'threshold' => (int) $row['threshold_value'],
                'window'    => (int) $row['window_seconds'],
                'action'    => (string) $row['action'],
                'severity'  => (string) $row['severity'],
            ];
        }

        return $keyed;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByKey(string $ruleKey): ?array
    {
        return $this->findBy('rule_key', $ruleKey);
    }
}
