<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Device health telemetry.
 *
 * Time-series data, pruned by retention policy. The aggregate health score on
 * the device row is the long-lived record; these rows exist to draw the graphs
 * and to diagnose a station that has started misbehaving.
 *
 * @package App\Repositories
 * @version 1.0.0
 */
final class DeviceHeartbeatRepository extends BaseRepository
{
    protected string $table = 'device_heartbeats';
    protected string $primaryKey = 'heartbeat_id';
    protected bool $timestamps = false;
    protected ?string $softDeleteColumn = null;

    protected array $fillable = [
        'device_id', 'firmware_version', 'ip_address', 'signal_strength', 'free_heap_bytes',
        'heap_total_bytes', 'memory_usage_pct', 'cpu_usage_pct', 'temperature_c',
        'battery_level_pct', 'uptime_seconds', 'queued_requests', 'last_scan_at',
        'last_verification_at', 'reported_status', 'received_at',
    ];

    protected array $sortable = ['received_at', 'signal_strength', 'uptime_seconds'];

    /**
     * Recent heartbeats for a station.
     *
     * @return list<array<string,mixed>>
     */
    public function recentForDevice(int $deviceId, int $limit = 60): array
    {
        return $this->query()
            ->whereEquals('device_id', $deviceId)
            ->orderBy('received_at', 'DESC')
            ->limit($limit)
            ->get();
    }

    /**
     * Telemetry series for the diagnostics charts, oldest first.
     *
     * @return list<array<string,mixed>>
     */
    public function seriesForDevice(int $deviceId, int $hours = 6): array
    {
        return $this->connection->select(
            'SELECT `received_at`, `signal_strength`, `memory_usage_pct`, `cpu_usage_pct`,
                    `temperature_c`, `free_heap_bytes`, `queued_requests`
               FROM `device_heartbeats`
              WHERE `device_id` = :device AND `received_at` >= :since
              ORDER BY `received_at`',
            [
                'device' => $deviceId,
                'since'  => now()->modify('-' . max(1, $hours) . ' hours')->format('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * How many heartbeats arrived from a station in a window.
     *
     * Compared against how many were expected, this is the reliability figure
     * the health score is built on.
     */
    public function countInWindow(int $deviceId, int $seconds): int
    {
        return (int) $this->connection->scalar(
            'SELECT COUNT(*) FROM `device_heartbeats` WHERE `device_id` = ? AND `received_at` >= ?',
            [$deviceId, now()->modify('-' . max(1, $seconds) . ' seconds')->format('Y-m-d H:i:s')]
        );
    }

    /**
     * Averages across a window, used by the health score.
     *
     * @return array{signal:float,memory:float,cpu:float,temperature:float}
     */
    public function averagesForDevice(int $deviceId, int $hours = 24): array
    {
        $row = $this->connection->selectOne(
            'SELECT AVG(`signal_strength`) AS `signal`,
                    AVG(`memory_usage_pct`) AS `memory`,
                    AVG(`cpu_usage_pct`) AS `cpu`,
                    AVG(`temperature_c`) AS `temperature`
               FROM `device_heartbeats`
              WHERE `device_id` = :device AND `received_at` >= :since',
            [
                'device' => $deviceId,
                'since'  => now()->modify('-' . max(1, $hours) . ' hours')->format('Y-m-d H:i:s'),
            ]
        ) ?? [];

        return [
            'signal'      => round((float) ($row['signal'] ?? 0), 1),
            'memory'      => round((float) ($row['memory'] ?? 0), 1),
            'cpu'         => round((float) ($row['cpu'] ?? 0), 1),
            'temperature' => round((float) ($row['temperature'] ?? 0), 1),
        ];
    }

    /**
     * Discard telemetry older than the retention window.
     */
    public function prune(int $retentionDays): int
    {
        if ($retentionDays <= 0) {
            return 0;
        }

        return $this->connection->execute(
            'DELETE FROM `device_heartbeats` WHERE `received_at` < ?',
            [now()->modify('-' . $retentionDays . ' days')->format('Y-m-d H:i:s')]
        );
    }
}
