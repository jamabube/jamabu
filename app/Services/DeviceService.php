<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Connection;
use App\Core\Database\Paginator;
use App\Core\Events\EventDispatcher;
use App\Core\Support\Str;
use App\Events\DeviceCameOnline;
use App\Events\DeviceWentOffline;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Repositories\AccessLogRepository;
use App\Repositories\ApiRequestLogRepository;
use App\Repositories\DeviceHeartbeatRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\OperatorSessionRepository;

/**
 * Monitoring station management and health.
 *
 * @package App\Services
 * @version 1.0.0
 */
class DeviceService
{
    public function __construct(
        private readonly DeviceRepository $devices,
        private readonly DeviceHeartbeatRepository $heartbeats,
        private readonly OperatorSessionRepository $operators,
        private readonly AccessLogRepository $accessLogs,
        private readonly ApiRequestLogRepository $apiLogs,
        private readonly DeviceAuthenticationService $deviceAuth,
        private readonly AuditService $audit,
        private readonly EventDispatcher $events,
        private readonly Connection $connection
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginate(array $filters, array $options): Paginator
    {
        return $this->devices->paginate($filters, $options);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function allWithStatus(): array
    {
        return $this->devices->allWithStatus();
    }

    /**
     * Register a station and issue its credentials.
     *
     * The API key is returned exactly once. It is stored only as a hash, so it
     * cannot be recovered afterwards — the same guarantee a password has.
     *
     * @param array<string,mixed> $attributes
     *
     * @return array{device_id:int,api_key:string}
     *
     * @throws ConflictException
     */
    public function register(array $attributes, ?int $actorId): array
    {
        $deviceCode = trim((string) ($attributes['device_code'] ?? ''));

        if ($deviceCode === '') {
            $deviceCode = $this->devices->nextDeviceCode((string) ($attributes['gate_type'] ?? 'both'));
        }

        if ($this->devices->existsWhere('device_code', $deviceCode, null)) {
            throw ConflictException::duplicate('device', 'code', $deviceCode);
        }

        if (isset($attributes['mac_address']) && $attributes['mac_address'] !== '') {
            $attributes['mac_address'] = Str::normaliseMac((string) $attributes['mac_address']);

            if ($this->devices->existsWhere('mac_address', $attributes['mac_address'], null)) {
                throw ConflictException::duplicate('device', 'MAC address', (string) $attributes['mac_address']);
            }
        }

        $credentials = $this->deviceAuth->issueApiKey();

        $deviceId = $this->devices->create(array_merge($attributes, [
            'device_code'         => $deviceCode,
            'api_key_hash'        => $credentials['hash'],
            'api_key_prefix'      => $credentials['prefix'],
            'signing_secret_hash' => $credentials['signing_hash'],
            'api_key_issued_at'   => now()->format('Y-m-d H:i:s'),
            'created_by'          => $actorId,
            'updated_by'          => $actorId,
        ]));

        $this->audit->created('devices', 'devices', $deviceId, sprintf(
            'Monitoring station %s was registered and issued an API key.',
            $deviceCode
        ), ['device_code' => $deviceCode, 'gate_type' => $attributes['gate_type'] ?? 'both']);

        return ['device_id' => $deviceId, 'api_key' => $credentials['key']];
    }

    /**
     * @param array<string,mixed> $attributes
     */
    public function update(int $deviceId, array $attributes, ?int $actorId): void
    {
        $existing = $this->devices->findOrFail($deviceId);

        if (isset($attributes['mac_address']) && $attributes['mac_address'] !== '') {
            $attributes['mac_address'] = Str::normaliseMac((string) $attributes['mac_address']);

            if ($this->devices->existsWhere('mac_address', $attributes['mac_address'], $deviceId)) {
                throw ConflictException::duplicate('device', 'MAC address', (string) $attributes['mac_address']);
            }
        }

        $this->devices->update($deviceId, array_merge($attributes, ['updated_by' => $actorId]));

        $this->audit->updated('devices', 'devices', $deviceId, sprintf(
            'Monitoring station %s was updated.',
            (string) $existing['device_code']
        ), $existing, $attributes);
    }

    /**
     * Issue a new API key, immediately invalidating the previous one.
     *
     * @return string The new key, shown once.
     */
    public function rotateApiKey(int $deviceId, ?int $actorId): string
    {
        $device      = $this->devices->findOrFail($deviceId);
        $credentials = $this->deviceAuth->issueApiKey();

        $this->devices->rotateApiKey(
            $deviceId,
            $credentials['hash'],
            $credentials['prefix'],
            $credentials['signing_hash'],
            $actorId
        );

        // Rotating a key strands the running firmware until it is reflashed, so
        // the fact is recorded prominently rather than as a routine update.
        $this->audit->record('devices', 'key_rotated', sprintf(
            'The API key for %s was rotated. The previous key is now invalid and the station must be reconfigured.',
            (string) $device['device_code']
        ), ['record_type' => 'devices', 'record_id' => $deviceId]);

        return $credentials['key'];
    }

    /**
     * Bar a station from communicating for a period.
     */
    public function suspend(int $deviceId, int $minutes, string $reason): void
    {
        $device = $this->devices->findOrFail($deviceId);

        $this->devices->suspend(
            $deviceId,
            now()->modify('+' . max(1, $minutes) . ' minutes')->format('Y-m-d H:i:s'),
            $reason
        );

        $this->audit->record('devices', 'suspended', sprintf(
            'Monitoring station %s was suspended for %d minute(s): %s',
            (string) $device['device_code'],
            $minutes,
            $reason
        ), ['record_type' => 'devices', 'record_id' => $deviceId]);
    }

    public function reinstate(int $deviceId): void
    {
        $device = $this->devices->findOrFail($deviceId);

        $this->devices->reinstate($deviceId);

        $this->audit->record('devices', 'reinstated', sprintf(
            'Monitoring station %s was returned to service.',
            (string) $device['device_code']
        ), ['record_type' => 'devices', 'record_id' => $deviceId]);
    }

    /**
     * Decommission a station.
     *
     * @throws BusinessRuleException
     */
    public function decommission(int $deviceId, ?int $actorId): void
    {
        $device = $this->devices->findOrFail($deviceId);

        // Removing a station that still has an open shift would leave a guard
        // recorded as on duty at a gate that no longer exists.
        if ($this->operators->activeForDevice($deviceId) !== null) {
            throw BusinessRuleException::withCode(
                'OPERATOR_ON_DUTY',
                'An operator is currently signed in at this station. End the shift before decommissioning it.'
            );
        }

        $this->connection->transaction(function () use ($deviceId, $actorId): void {
            $this->operators->closeAllForDevice($deviceId, 'administrator');
            $this->devices->update($deviceId, ['status' => 'decommissioned', 'updated_by' => $actorId]);
            $this->devices->delete($deviceId, $actorId);
        });

        $this->audit->deleted('devices', 'devices', $deviceId, sprintf(
            'Monitoring station %s was decommissioned.',
            (string) $device['device_code']
        ), ['device_code' => $device['device_code']]);
    }

    /**
     * Record a heartbeat and its telemetry.
     *
     * @param array<string,mixed> $telemetry
     */
    public function recordHeartbeat(int $deviceId, array $telemetry): void
    {
        $status = $this->devices->findWithStatus($deviceId);
        $wasOffline = $status !== null && (string) $status['connectivity'] === 'offline';
        $outage     = $status === null ? 0 : (int) ($status['seconds_since_heartbeat'] ?? 0);

        $freeHeap  = isset($telemetry['free_heap_bytes']) ? (int) $telemetry['free_heap_bytes'] : null;
        $totalHeap = isset($telemetry['heap_total_bytes']) ? (int) $telemetry['heap_total_bytes'] : null;

        // Memory usage is derived when the firmware reports raw heap figures,
        // so a device does not have to compute a percentage itself.
        $memoryUsage = isset($telemetry['memory_usage_pct'])
            ? (float) $telemetry['memory_usage_pct']
            : ($freeHeap !== null && $totalHeap !== null && $totalHeap > 0
                ? round((1 - ($freeHeap / $totalHeap)) * 100, 2)
                : null);

        $this->connection->transaction(function () use ($deviceId, $telemetry, $freeHeap, $totalHeap, $memoryUsage): void {
            $this->heartbeats->create([
                'device_id'            => $deviceId,
                'firmware_version'     => $telemetry['firmware_version'] ?? null,
                'ip_address'           => $telemetry['ip_address'] ?? null,
                'signal_strength'      => isset($telemetry['signal_strength']) ? (int) $telemetry['signal_strength'] : null,
                'free_heap_bytes'      => $freeHeap,
                'heap_total_bytes'     => $totalHeap,
                'memory_usage_pct'     => $memoryUsage,
                'cpu_usage_pct'        => isset($telemetry['cpu_usage_pct']) ? (float) $telemetry['cpu_usage_pct'] : null,
                'temperature_c'        => isset($telemetry['temperature_c']) ? (float) $telemetry['temperature_c'] : null,
                'battery_level_pct'    => isset($telemetry['battery_level_pct']) ? (int) $telemetry['battery_level_pct'] : null,
                'uptime_seconds'       => isset($telemetry['uptime_seconds']) ? (int) $telemetry['uptime_seconds'] : null,
                'queued_requests'      => (int) ($telemetry['queued_requests'] ?? 0),
                'last_scan_at'         => $telemetry['last_scan_at'] ?? null,
                'last_verification_at' => $telemetry['last_verification_at'] ?? null,
                'reported_status'      => $telemetry['status'] ?? 'ready',
                'received_at'          => now()->format('Y-m-d H:i:s'),
            ]);

            $this->devices->recordHeartbeat(
                $deviceId,
                isset($telemetry['signal_strength']) ? (int) $telemetry['signal_strength'] : null,
                isset($telemetry['uptime_seconds']) ? (int) $telemetry['uptime_seconds'] : null
            );
        });

        // A station whose uptime went backwards has restarted, which is worth
        // counting: frequent restarts are the clearest sign of a hardware or
        // power problem.
        if ($status !== null
            && isset($telemetry['uptime_seconds'])
            && $status['uptime_seconds'] !== null
            && (int) $telemetry['uptime_seconds'] < (int) $status['uptime_seconds']) {
            $this->devices->recordRestart($deviceId);
            $this->operators->closeAllForDevice($deviceId, 'device_restart');
        }

        if ($wasOffline && $status !== null) {
            $this->events->dispatch(new DeviceCameOnline(
                deviceId: $deviceId,
                deviceCode: (string) $status['device_code'],
                deviceName: (string) $status['device_name'],
                outageSeconds: $outage
            ));
        }
    }

    /**
     * Detect stations that have stopped reporting.
     *
     * Run by the maintenance task; a notification is raised once per outage
     * rather than on every check, which is what the health-score marker does.
     *
     * @return list<array<string,mixed>>
     */
    public function detectOfflineDevices(): array
    {
        $offline = $this->devices->offlineDevices();

        foreach ($offline as $device) {
            $deviceId = (int) $device['device_id'];

            // The health score is set to zero when the outage is first noticed,
            // so the next pass can tell "already reported" from "just failed".
            if ((int) ($device['health_score'] ?? 100) === 0) {
                continue;
            }

            $this->devices->updateHealthScore($deviceId, 0);
            $this->operators->closeAllForDevice($deviceId, 'device_restart');

            $this->events->dispatch(new DeviceWentOffline(
                deviceId: $deviceId,
                deviceCode: (string) $device['device_code'],
                deviceName: (string) $device['device_name'],
                location: $device['location'] === null ? null : (string) $device['location'],
                secondsSinceHeartbeat: (int) ($device['seconds_since_heartbeat'] ?? 0)
            ));
        }

        return $offline;
    }

    /**
     * Compute and store a station's health score.
     *
     * The weighting comes from configuration so the organisation can decide
     * what "healthy" means for its own hardware.
     */
    public function calculateHealthScore(int $deviceId): int
    {
        $device = $this->devices->findWithStatus($deviceId);

        if ($device === null) {
            return 0;
        }

        if ((string) $device['connectivity'] !== 'online') {
            $this->devices->updateHealthScore($deviceId, 0);

            return 0;
        }

        /** @var array<string,int> $weights */
        $weights  = (array) config('monitoring.device_health_weights', []);
        $interval = max(1, (int) $device['heartbeat_interval']);

        // Heartbeat reliability: how many beats arrived against how many were
        // expected over the last hour.
        $expected = (int) floor(3600 / $interval);
        $received = $this->heartbeats->countInWindow($deviceId, 3600);
        $reliability = $expected === 0 ? 1.0 : min(1.0, $received / $expected);

        // Communication success from the API log.
        $performance = $this->apiLogs->performanceSince(now()->modify('-24 hours')->format('Y-m-d H:i:s'));
        $successRate = $performance['total'] === 0
            ? 1.0
            : max(0.0, 1 - ($performance['failed'] / $performance['total']));

        // Signal strength mapped from the usable RSSI band onto 0..1.
        $signal        = (int) ($device['signal_strength'] ?? -90);
        $signalQuality = max(0.0, min(1.0, ($signal + 95) / 50));

        // Restarts and errors both count against the score, saturating so a
        // single very bad day cannot make the figure meaningless.
        $restartPenalty = min(1.0, (int) $device['restart_count'] / 20);
        $errorPenalty   = min(1.0, (int) $device['error_count'] / 50);
        $authPenalty    = min(1.0, (int) $device['auth_failure_count'] / 10);

        $score = ($weights['heartbeat_reliability'] ?? 30) * $reliability
            + ($weights['communication_success'] ?? 25) * $successRate
            + ($weights['signal_strength'] ?? 15) * $signalQuality
            + ($weights['restart_frequency'] ?? 10) * (1 - $restartPenalty)
            + ($weights['authentication_success'] ?? 10) * (1 - $authPenalty)
            + ($weights['recent_errors'] ?? 10) * (1 - $errorPenalty);

        $rounded = (int) round(max(0, min(100, $score)));

        $this->devices->updateHealthScore($deviceId, $rounded);

        return $rounded;
    }

    /**
     * The band a score falls into.
     */
    public function healthBand(int $score): string
    {
        /** @var array<string,int> $bands */
        $bands = (array) config('monitoring.device_health_bands', []);

        return match (true) {
            $score >= ($bands['excellent'] ?? 90) => 'excellent',
            $score >= ($bands['good'] ?? 75)      => 'good',
            $score >= ($bands['warning'] ?? 50)   => 'warning',
            default                               => 'critical',
        };
    }

    /**
     * Everything the diagnostics page shows for one station.
     *
     * @return array<string,mixed>
     */
    public function diagnostics(int $deviceId): array
    {
        $device = $this->devices->findWithStatus($deviceId);

        if ($device === null) {
            throw \App\Exceptions\NotFoundException::record('Device', $deviceId);
        }

        return [
            'device'        => $device,
            'health_band'   => $this->healthBand((int) ($device['health_score'] ?? 0)),
            'heartbeats'    => $this->heartbeats->recentForDevice($deviceId, 30),
            'telemetry'     => $this->heartbeats->seriesForDevice($deviceId, 6),
            'averages'      => $this->heartbeats->averagesForDevice($deviceId, 24),
            'communication' => $this->apiLogs->forDevice($deviceId, 25),
            'operator'      => $this->operators->activeForDevice($deviceId),
            'shifts'        => $this->operators->history(['device_id' => $deviceId], 10),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        return [
            'connectivity' => $this->devices->connectivityCounts(),
            'operators'    => $this->operators->countActive(),
        ];
    }
}
