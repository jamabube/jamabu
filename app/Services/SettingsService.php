<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database\Connection;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Core\Validation\Validator;
use App\Repositories\SettingRepository;
use Throwable;

/**
 * Runtime settings.
 *
 * Values live in the database so an administrator can retune the system
 * without a deployment; the configuration files supply the defaults.
 *
 * There is exactly one way the rest of the application reads a setting:
 * applyToConfiguration() overlays the stored values onto the configuration
 * tree at the start of every request, and everything downstream calls
 * config(). Services deliberately do not read this class directly — two paths
 * to the same value is how they end up disagreeing, and it makes an override
 * (in a test, or in a console command) apply in some places but not others.
 *
 * Reads are cached for the lifetime of the request, and a failure to reach the
 * settings table falls back to configuration rather than taking the
 * application down: a settings lookup must never be the reason a guard cannot
 * record a vehicle.
 *
 * @package App\Services
 * @version 1.0.0
 */
class SettingsService
{
    /** @var array<string,mixed>|null Lazily loaded key => typed value. */
    private ?array $cache = null;

    /** Mapping from a settings key onto the configuration key it overrides. */
    private const CONFIG_MAP = [
        'app.name'                            => 'app.name',
        'app.organization'                    => 'app.organization',
        'app.timezone'                        => 'app.timezone',
        'app.locale'                          => 'app.locale',
        'app.copyright'                       => 'app.copyright',
        'app.support_contact'                 => 'app.support.administrator',
        'ui.records_per_page'                 => 'app.pagination.default_per_page',
        'ui.dashboard_refresh'                => 'monitoring.live.dashboard_refresh',
        'ui.live_poll_interval'               => 'monitoring.live.poll_interval_seconds',
        'security.session_timeout'            => 'session.lifetime',
        'security.session_absolute'           => 'session.absolute_lifetime',
        'security.single_session'             => 'session.concurrency.single_session',
        'security.max_login_attempts'         => 'security.lockout.max_attempts',
        'security.lock_minutes'               => 'security.lockout.lock_minutes',
        'security.password_min_length'        => 'security.password.min_length',
        'security.password_max_age_days'      => 'security.password.max_age_days',
        'security.password_history'           => 'security.password.history_depth',
        'security.force_https'                => 'security.transport.force_https',
        'api.rate_limit'                      => 'api.rate_limit.default.limit',
        'api.rate_window'                     => 'api.rate_limit.default.window',
        'api.timestamp_tolerance'             => 'api.device.timestamp_tolerance',
        'api.nonce_ttl'                       => 'api.device.nonce_ttl',
        'api.require_signature'               => 'api.device.require_signature',
        'api.flood_threshold'                 => 'api.flood.threshold',
        'device.heartbeat_interval'           => 'api.device.heartbeat_interval',
        'device.offline_after'                => 'api.device.offline_after',
        'device.scan_debounce'                => 'api.device.scan_debounce',
        'monitoring.require_operator'         => 'monitoring.rules.require_operator_authentication',
        'monitoring.operator_session_minutes' => 'monitoring.rules.operator_session_minutes',
        'monitoring.minimum_stay'             => 'monitoring.rules.minimum_stay_seconds',
        'monitoring.duplicate_window'         => 'monitoring.rules.duplicate_scan_window_seconds',
        'monitoring.overstay_hours'           => 'monitoring.rules.overstay_alert_hours',
        'monitoring.visitor_validity_hours'   => 'monitoring.visitor.default_validity_hours',
        'backup.schedule'                     => 'backup.schedule',
        'backup.retention'                    => 'backup.retention',
        'backup.compress'                     => 'backup.compress',
        'notifications.poll_interval'         => 'notifications.poll_interval',
        'notifications.mail_enabled'          => 'notifications.channels.mail',
        'notifications.mail_host'             => 'notifications.mail.host',
        'maintenance.enabled'                 => 'app.maintenance.enabled',
        'maintenance.message'                 => 'app.maintenance.message',
    ];

    public function __construct(
        private readonly SettingRepository $repository,
        private readonly Connection $connection
    ) {
    }

    /**
     * Read a setting, falling back to the configuration default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $values = $this->load();

        if (array_key_exists($key, $values)) {
            return $values[$key];
        }

        $configKey = self::CONFIG_MAP[$key] ?? null;

        return $configKey === null ? $default : Config::get($configKey, $default);
    }

    public function getInt(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) (is_scalar($value) ? $value : '')), ['1', 'true', 'yes', 'on'], true);
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Overlay every database setting onto the configuration tree.
     *
     * Called once per request during bootstrap so that code reading
     * config('session.lifetime') transparently sees the administrator's value.
     */
    public function applyToConfiguration(): void
    {
        foreach ($this->load() as $key => $value) {
            $configKey = self::CONFIG_MAP[$key] ?? null;

            if ($configKey !== null) {
                Config::set($configKey, $value);
            }
        }
    }

    /**
     * Update a group of settings after validating each against its own rule.
     *
     * @param array<string,mixed> $values key => new value
     *
     * @return array<string,array{old:mixed,new:mixed}> The settings that changed.
     *
     * @throws ValidationException
     */
    public function updateMany(array $values, ?int $updatedBy, Validator $validator): array
    {
        $definitions = $this->repository->allKeyed();
        $rules       = [];
        $labels      = [];
        $candidate   = [];

        foreach ($values as $key => $value) {
            if (!isset($definitions[$key])) {
                // An unknown key is a client error, not something to persist.
                continue;
            }

            $definition = $definitions[$key];

            if ((int) $definition['is_editable'] !== 1) {
                continue;
            }

            $rule = (string) ($definition['validation'] ?? '');
            if ($rule !== '') {
                $rules[$key] = $rule;
            }

            $labels[$key]    = (string) $definition['label'];
            $candidate[$key] = $value;
        }

        if ($rules !== []) {
            $validator->validate($candidate, $rules, $labels);
        }

        $changed = [];

        $this->connection->transaction(function () use ($candidate, $definitions, $updatedBy, &$changed): void {
            foreach ($candidate as $key => $value) {
                $definition = $definitions[$key];
                $stored     = $this->normaliseForStorage($value, (string) $definition['value_type']);
                $previous   = $definition['value'];

                if ((string) $previous === (string) $stored) {
                    continue;
                }

                $this->repository->setValue($key, $stored, $updatedBy);

                $changed[$key] = [
                    // A sensitive value must not appear in the audit record it
                    // triggers, so the change is recorded without its content.
                    'old' => (int) $definition['is_sensitive'] === 1 ? '[redacted]' : $previous,
                    'new' => (int) $definition['is_sensitive'] === 1 ? '[redacted]' : $stored,
                ];
            }
        });

        $this->flush();

        return $changed;
    }

    /**
     * Restore one setting to its shipped default.
     *
     * @throws NotFoundException
     */
    public function resetToDefault(string $key, ?int $updatedBy): void
    {
        if ($this->repository->findByKey($key) === null) {
            throw NotFoundException::record('Setting', $key);
        }

        $this->repository->resetToDefault($key, $updatedBy);
        $this->flush();
    }

    /**
     * Settings grouped for the settings page, with sensitive values masked.
     *
     * @return array<string,list<array<string,mixed>>>
     */
    public function groupedForDisplay(): array
    {
        $groups = $this->repository->grouped();

        foreach ($groups as $group => $rows) {
            foreach ($rows as $index => $row) {
                if ((int) $row['is_sensitive'] === 1 && (string) $row['value'] !== '') {
                    $groups[$group][$index]['value'] = '';
                    $groups[$group][$index]['has_value'] = true;
                    continue;
                }

                $groups[$group][$index]['has_value'] = (string) ($row['value'] ?? '') !== '';
            }
        }

        return $groups;
    }

    /**
     * Discard the request cache, forcing the next read to hit the database.
     */
    public function flush(): void
    {
        $this->cache = null;
    }

    /**
     * Load and type-cast every setting.
     *
     * @return array<string,mixed>
     */
    private function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        try {
            $rows = $this->repository->allKeyed();
        } catch (Throwable $e) {
            // Before the first migration the table does not exist yet, and a
            // transient database fault should degrade to configuration
            // defaults rather than break every page.
            logger()->channel('application')->warning('Runtime settings unavailable; using configuration defaults.', [
                'reason' => $e->getMessage(),
            ]);

            return $this->cache = [];
        }

        $values = [];

        foreach ($rows as $key => $row) {
            $values[$key] = $this->cast($row['value'], (string) $row['value_type']);
        }

        return $this->cache = $values;
    }

    /**
     * Cast a stored string into the type the setting declares.
     */
    private function cast(mixed $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        $raw = (string) $value;

        return match ($type) {
            'integer'  => (int) $raw,
            'float'    => (float) $raw,
            'boolean'  => in_array(strtolower($raw), ['1', 'true', 'yes', 'on'], true),
            'json'     => json_decode($raw, true) ?? [],
            default    => $raw,
        };
    }

    /**
     * Convert a submitted value into the string form stored in the table.
     */
    private function normaliseForStorage(mixed $value, string $type): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'boolean' => in_array(strtolower((string) (is_scalar($value) ? $value : '')), ['1', 'true', 'yes', 'on'], true) ? '1' : '0',
            'integer' => (string) (int) $value,
            'float'   => (string) (float) $value,
            'json'    => is_array($value) ? (string) json_encode($value) : (string) $value,
            default   => is_scalar($value) ? (string) $value : '',
        };
    }
}
