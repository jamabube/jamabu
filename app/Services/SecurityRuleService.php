<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Exceptions\NotFoundException;
use App\Repositories\SecurityRuleRepository;
use Throwable;

/**
 * Configurable security thresholds.
 *
 * A rule row is the authority for one threshold. Rather than teaching every
 * enforcement point about this table, the enabled rules are overlaid onto the
 * configuration tree once per request — exactly as the settings are — so the
 * lockout check, the rate limiter and the flood detector keep reading config()
 * and automatically honour whatever the security officer has set.
 *
 * A disabled rule falls back to the shipped configuration default rather than
 * to "no limit": switching a rule off must never remove a protection.
 *
 * @package App\Services
 * @version 1.0.0
 */
class SecurityRuleService
{
    /**
     * Rule key => the configuration keys its threshold and window feed.
     *
     * A null entry means that half of the rule has no configuration
     * counterpart: the rule documents a behaviour that is not tunable, such as
     * a replayed nonce, which is always rejected on the first occurrence.
     *
     * @var array<string,array{threshold:?string,window:?string}>
     */
    private const CONFIG_MAP = [
        'login.attempts'        => ['threshold' => 'security.lockout.max_attempts',            'window' => null],
        'login.ip_attempts'     => ['threshold' => 'security.lockout.ip_max_attempts',         'window' => null],
        'api.rate_limit'        => ['threshold' => 'api.rate_limit.default.limit',             'window' => 'api.rate_limit.default.window'],
        'api.flood'             => ['threshold' => 'api.flood.threshold',                      'window' => 'api.flood.window'],
        'api.identical_payload' => ['threshold' => 'api.flood.identical_payload_threshold',    'window' => null],
        'api.failures'          => ['threshold' => 'api.flood.failure_threshold',              'window' => null],
        'device.auth_failures'  => ['threshold' => 'api.device.max_auth_failures',             'window' => null],
        'device.replay'         => ['threshold' => null,                                       'window' => 'api.device.nonce_ttl'],
        'rfid.unknown'          => ['threshold' => 'monitoring.rules.unknown_tag_alert_count', 'window' => 'monitoring.rules.unknown_tag_alert_window'],
        'fingerprint.failures'  => ['threshold' => 'monitoring.rules.fingerprint_alert_count', 'window' => 'monitoring.rules.fingerprint_alert_window'],
        'session.fingerprint'   => ['threshold' => null,                                       'window' => null],
    ];

    /** @var array<string,array{threshold:int,window:int,action:string,severity:string}>|null */
    private ?array $cache = null;

    public function __construct(
        private readonly SecurityRuleRepository $repository,
        private readonly AuditService $audit
    ) {
    }

    /**
     * Overlay the enabled rules onto the configuration tree.
     *
     * The window is expressed in seconds in the table; the lockout window is
     * held in minutes in configuration, so it is converted rather than being
     * written through unchanged.
     */
    public function applyToConfiguration(): void
    {
        foreach ($this->load() as $key => $rule) {
            $mapping = self::CONFIG_MAP[$key] ?? null;

            if ($mapping === null) {
                continue;
            }

            if ($mapping['threshold'] !== null) {
                Config::set($mapping['threshold'], $rule['threshold']);
            }

            if ($mapping['window'] !== null) {
                Config::set($mapping['window'], $rule['window']);
            }
        }

        $lockout = $this->load()['login.ip_attempts'] ?? null;

        if ($lockout !== null && $lockout['window'] > 0) {
            Config::set('security.lockout.ip_window_minutes', max(1, (int) round($lockout['window'] / 60)));
        }
    }

    /**
     * Every rule, for the administration screen.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        return $this->repository->all();
    }

    /**
     * The threshold for a rule, falling back to a caller-supplied default when
     * the rule is disabled or absent.
     */
    public function threshold(string $ruleKey, int $default): int
    {
        return $this->load()[$ruleKey]['threshold'] ?? $default;
    }

    /**
     * The window in seconds for a rule, with the same fallback behaviour.
     */
    public function window(string $ruleKey, int $default): int
    {
        return $this->load()[$ruleKey]['window'] ?? $default;
    }

    /**
     * Whether a rule is enabled at all.
     */
    public function isEnabled(string $ruleKey): bool
    {
        return isset($this->load()[$ruleKey]);
    }

    /**
     * Change a rule.
     *
     * @param array<string,mixed> $attributes
     *
     * @throws NotFoundException
     */
    public function update(int $ruleId, array $attributes, ?int $actorId): void
    {
        $existing = $this->repository->find($ruleId);

        if ($existing === null) {
            throw NotFoundException::record('Security rule', $ruleId);
        }

        // The key is the contract between this row and the code that reads it;
        // renaming it would silently detach the rule from what it governs.
        unset($attributes['rule_key']);

        $this->repository->update($ruleId, array_merge($attributes, ['updated_by' => $actorId]));
        $this->flush();

        $this->audit->updated('security', 'security_rules', $ruleId, sprintf(
            'Security rule "%s" was changed.',
            (string) $existing['rule_name']
        ), $existing, $attributes);
    }

    /**
     * Discard the cached rules so the next read reloads them.
     */
    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * @return array<string,array{threshold:int,window:int,action:string,severity:string}>
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            $this->cache = $this->repository->enabledKeyed();
        } catch (Throwable) {
            // An unreachable table must not stop the request: the shipped
            // configuration defaults remain in force, which are the stricter
            // ones a fresh installation runs with.
            $this->cache = [];
        }

        return $this->cache;
    }
}
