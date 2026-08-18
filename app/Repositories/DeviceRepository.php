<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database\Paginator;
use App\Core\Database\QueryBuilder;

/**
 * ESP32 monitoring station storage.
 *
 * The API key is held as a hash, never in clear text: the plain value is shown
 * once at issue and is thereafter unrecoverable, exactly like a password.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class DeviceRepository extends BaseRepository
{
    protected string $table = 'devices';
    protected string $primaryKey = 'device_id';

    protected array $fillable = [
        'device_code', 'device_name', 'description', 'api_key_hash', 'api_key_prefix',
        'api_key_issued_at', 'api_key_rotated_at', 'api_key_rotated_by', 'signing_secret_hash',
        'mac_address', 'ip_address', 'allowed_ip', 'firmware_version', 'previous_firmware',
        'firmware_updated_at', 'location', 'gate_type', 'gate_label', 'installation_date',
        'heartbeat_interval', 'status', 'suspended_until', 'suspend_reason', 'remarks',
        'created_by', 'updated_by',
    ];

    protected array $sortable = [
        'device_code', 'device_name', 'location', 'gate_type', 'status',
        'last_heartbeat_at', 'health_score', 'firmware_version', 'created_at',
    ];

    protected array $searchable = ['device_code', 'device_name', 'location', 'mac_address', 'ip_address', 'gate_label'];

    /**
     * Query the status view, which applies the online/offline decision once.
     */
    private function statusView(): QueryBuilder
    {
        return (new QueryBuilder($this->connection))->table('v_device_status')->whereNull('deleted_at');
    }

    /**
     * Find a device by the code its firmware transmits.
     *
     * @return array<string,mixed>|null
     */
    public function findByCode(string $deviceCode): ?array
    {
        return $this->queryWithTrashed()->whereEquals('device_code', $deviceCode)->first();
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findWithStatus(int $deviceId): ?array
    {
        return $this->statusView()->whereEquals('device_id', $deviceId)->first();
    }

    /**
     * Every device with its derived connectivity, for the device grid.
     *
     * @return list<array<string,mixed>>
     */
    public function allWithStatus(): array
    {
        return $this->statusView()->orderBy('device_name')->get();
    }

    /**
     * @param array<string,mixed> $filters
     */
    public function filtered(array $filters): QueryBuilder
    {
        $query = $this->statusView();

        if (($filters['search'] ?? '') !== '') {
            $query->whereAnyLike($this->searchable, (string) $filters['search']);
        }

        foreach (['status', 'gate_type', 'connectivity'] as $column) {
            if (($filters[$column] ?? '') !== '') {
                $query->whereEquals($column, (string) $filters[$column]);
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

        $sort = (string) ($options['sort'] ?? 'device_name');
        $query->orderBy($this->assertSortable($sort), (string) ($options['direction'] ?? 'ASC'));

        return Paginator::fromQuery(
            $query,
            max(1, (int) ($options['page'] ?? 1)),
            (int) ($options['per_page'] ?? config('app.pagination.default_per_page', 25))
        );
    }

    // ------------------------------------------------------------------
    // Communication bookkeeping
    // ------------------------------------------------------------------

    /**
     * Record a successful communication.
     */
    public function recordCommunication(int $deviceId, string $ipAddress, ?string $firmwareVersion = null): void
    {
        $sql = 'UPDATE `devices`
                   SET `last_communication_at` = :now,
                       `ip_address` = :ip,
                       `communication_count` = `communication_count` + 1,
                       `auth_failure_count` = 0';

        $bindings = ['now' => $this->timestamp(), 'ip' => $ipAddress, 'id' => $deviceId];

        // A firmware change is worth recording: it is the first thing to check
        // when a station starts behaving differently.
        if ($firmwareVersion !== null && $firmwareVersion !== '') {
            $sql .= ', `previous_firmware` = CASE WHEN `firmware_version` <> :firmware THEN `firmware_version` ELSE `previous_firmware` END,
                       `firmware_updated_at` = CASE WHEN `firmware_version` <> :firmware THEN :now ELSE `firmware_updated_at` END,
                       `firmware_version` = :firmware';
            $bindings['firmware'] = $firmwareVersion;
        }

        $sql .= ' WHERE `device_id` = :id';

        $this->connection->execute($sql, $bindings);
    }

    /**
     * Record a heartbeat against the device row.
     */
    public function recordHeartbeat(int $deviceId, ?int $signalStrength, ?int $uptimeSeconds): void
    {
        $this->connection->execute(
            'UPDATE `devices`
                SET `last_heartbeat_at` = :now,
                    `last_communication_at` = :now,
                    `signal_strength` = COALESCE(:signal, `signal_strength`),
                    `uptime_seconds` = COALESCE(:uptime, `uptime_seconds`)
              WHERE `device_id` = :id',
            ['now' => $this->timestamp(), 'signal' => $signalStrength, 'uptime' => $uptimeSeconds, 'id' => $deviceId]
        );
    }

    public function recordScan(int $deviceId): void
    {
        $this->connection->execute(
            'UPDATE `devices` SET `last_scan_at` = :now WHERE `device_id` = :id',
            ['now' => $this->timestamp(), 'id' => $deviceId]
        );
    }

    /**
     * Count an authentication failure and return the running total.
     */
    public function recordAuthenticationFailure(int $deviceId): int
    {
        $this->connection->execute(
            'UPDATE `devices` SET `auth_failure_count` = `auth_failure_count` + 1 WHERE `device_id` = :id',
            ['id' => $deviceId]
        );

        return (int) $this->connection->scalar(
            'SELECT `auth_failure_count` FROM `devices` WHERE `device_id` = ?',
            [$deviceId]
        );
    }

    public function recordError(int $deviceId): void
    {
        $this->connection->execute(
            'UPDATE `devices` SET `error_count` = `error_count` + 1 WHERE `device_id` = :id',
            ['id' => $deviceId]
        );
    }

    public function recordRestart(int $deviceId): void
    {
        $this->connection->execute(
            'UPDATE `devices` SET `restart_count` = `restart_count` + 1 WHERE `device_id` = :id',
            ['id' => $deviceId]
        );
    }

    /**
     * Suspend a device from communicating.
     */
    public function suspend(int $deviceId, string $until, string $reason): void
    {
        $this->connection->execute(
            'UPDATE `devices`
                SET `status` = :status, `suspended_until` = :until, `suspend_reason` = :reason, `updated_at` = :now
              WHERE `device_id` = :id',
            [
                'status' => 'suspended',
                'until'  => $until,
                'reason' => mb_substr($reason, 0, 255),
                'now'    => $this->timestamp(),
                'id'     => $deviceId,
            ]
        );
    }

    public function reinstate(int $deviceId): void
    {
        $this->connection->execute(
            'UPDATE `devices`
                SET `status` = :status, `suspended_until` = NULL, `suspend_reason` = NULL,
                    `auth_failure_count` = 0, `updated_at` = :now
              WHERE `device_id` = :id',
            ['status' => 'active', 'now' => $this->timestamp(), 'id' => $deviceId]
        );
    }

    /**
     * Lift suspensions whose period has elapsed.
     */
    public function releaseExpiredSuspensions(): int
    {
        return $this->connection->execute(
            "UPDATE `devices`
                SET `status` = 'active', `suspended_until` = NULL, `suspend_reason` = NULL, `auth_failure_count` = 0
              WHERE `status` = 'suspended' AND `suspended_until` IS NOT NULL AND `suspended_until` <= ?",
            [$this->timestamp()]
        );
    }

    /**
     * Replace a device's API key.
     */
    public function rotateApiKey(int $deviceId, string $keyHash, string $keyPrefix, string $signingSecretHash, ?int $rotatedBy): void
    {
        $this->connection->execute(
            'UPDATE `devices`
                SET `api_key_hash` = :hash, `api_key_prefix` = :prefix, `signing_secret_hash` = :signing,
                    `api_key_rotated_at` = :now, `api_key_rotated_by` = :by, `auth_failure_count` = 0,
                    `updated_at` = :now
              WHERE `device_id` = :id',
            [
                'hash'    => $keyHash,
                'prefix'  => $keyPrefix,
                'signing' => $signingSecretHash,
                'now'     => $this->timestamp(),
                'by'      => $rotatedBy,
                'id'      => $deviceId,
            ]
        );
    }

    public function updateHealthScore(int $deviceId, int $score): void
    {
        $this->connection->execute(
            'UPDATE `devices` SET `health_score` = :score WHERE `device_id` = :id',
            ['score' => max(0, min(100, $score)), 'id' => $deviceId]
        );
    }

    // ------------------------------------------------------------------
    // Aggregates
    // ------------------------------------------------------------------

    /**
     * @return array{online:int,offline:int,never_seen:int,disabled:int,total:int}
     */
    public function connectivityCounts(): array
    {
        $rows = $this->connection->select(
            'SELECT `connectivity`, COUNT(*) AS `total` FROM `v_device_status` WHERE `deleted_at` IS NULL GROUP BY `connectivity`'
        );

        $counts = ['online' => 0, 'offline' => 0, 'never_seen' => 0, 'disabled' => 0, 'total' => 0];

        foreach ($rows as $row) {
            $counts[(string) $row['connectivity']] = (int) $row['total'];
            $counts['total'] += (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Devices that have gone quiet, for the offline-detection task.
     *
     * @return list<array<string,mixed>>
     */
    public function offlineDevices(): array
    {
        return $this->statusView()->whereEquals('connectivity', 'offline')->get();
    }

    /**
     * Whether a device is permitted to record a movement of this type.
     *
     * An exit-lane station recording an entry would corrupt the presence
     * figures, so the gate role is enforced rather than assumed.
     *
     * @param array<string,mixed> $device
     */
    public function permitsAccessType(array $device, string $accessType): bool
    {
        $gateType = (string) ($device['gate_type'] ?? 'both');

        return $gateType === 'both' || $gateType === $accessType;
    }

    /**
     * Generate the next device code in a series.
     */
    public function nextDeviceCode(string $gateType): string
    {
        $prefix = 'ESP32-' . strtoupper($gateType === 'both' ? 'GATE' : $gateType);

        $highest = (string) $this->connection->scalar(
            'SELECT `device_code` FROM `devices`
              WHERE `device_code` LIKE :prefix
              ORDER BY LENGTH(`device_code`) DESC, `device_code` DESC
              LIMIT 1',
            ['prefix' => $prefix . '-%']
        );

        $sequence = $highest === '' ? 0 : (int) substr($highest, strlen($prefix) + 1);

        return sprintf('%s-%02d', $prefix, $sequence + 1);
    }
}
