<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Connection;
use App\Repositories\ApiRequestLogRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\ErrorLogRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\UserSessionRepository;
use Throwable;

/**
 * System health reporting.
 *
 * Each check answers one question an administrator would otherwise have to log
 * into the server to answer, and every one degrades to "unknown" rather than
 * throwing: a health dashboard that breaks when something is unhealthy is
 * worse than useless.
 *
 * @package App\Services
 * @version 1.0.0
 */
class SystemHealthService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly DeviceRepository $devices,
        private readonly ErrorLogRepository $errorLogs,
        private readonly SecurityEventRepository $securityEvents,
        private readonly ApiRequestLogRepository $apiLogs,
        private readonly UserSessionRepository $sessions,
        private readonly NotificationRepository $notifications
    ) {
    }

    /**
     * The full health report.
     *
     * @return array<string,mixed>
     */
    public function report(): array
    {
        $checks = [
            'database'    => $this->checkDatabase(),
            'storage'     => $this->checkStorage(),
            'logs'        => $this->checkLogWritability(),
            'devices'     => $this->checkDevices(),
            'performance' => $this->checkPerformance(),
            'errors'      => $this->checkErrors(),
            'security'    => $this->checkSecurity(),
            'backups'     => $this->checkBackups(),
        ];

        // The overall state is the worst individual state: an administrator
        // needs to see "something is wrong", not an average that hides it.
        $overall = 'healthy';

        foreach ($checks as $check) {
            if ($check['state'] === 'critical') {
                $overall = 'critical';
                break;
            }

            if ($check['state'] === 'warning') {
                $overall = 'warning';
            }
        }

        return [
            'overall'      => $overall,
            'checks'       => $checks,
            'environment'  => $this->environment(),
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDatabase(): array
    {
        try {
            $startedAt = microtime(true);
            $healthy   = $this->connection->isHealthy();
            $latency   = round((microtime(true) - $startedAt) * 1000, 2);

            if (!$healthy) {
                return $this->result('critical', 'The database is not reachable.', []);
            }

            $size = $this->connection->selectOne(
                'SELECT ROUND(SUM(`data_length` + `index_length`) / 1048576, 2) AS `size_mb`,
                        COUNT(*) AS `tables`
                   FROM INFORMATION_SCHEMA.TABLES
                  WHERE TABLE_SCHEMA = DATABASE()'
            ) ?? [];

            return $this->result(
                $latency > 500 ? 'warning' : 'healthy',
                sprintf('Connected in %.1f ms.', $latency),
                [
                    'server_version' => $this->connection->serverVersion(),
                    'database'       => $this->connection->databaseName(),
                    'latency_ms'     => $latency,
                    'size_mb'        => (float) ($size['size_mb'] ?? 0),
                    'tables'         => (int) ($size['tables'] ?? 0),
                ]
            );
        } catch (Throwable $e) {
            return $this->result('critical', 'The database check failed: ' . $e->getMessage(), []);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function checkStorage(): array
    {
        $path = base_path();

        $free  = @disk_free_space($path);
        $total = @disk_total_space($path);

        if ($free === false || $total === false || $total <= 0) {
            return $this->result('unknown', 'Disk usage could not be determined.', []);
        }

        $usedPercent = round((1 - ($free / $total)) * 100, 1);

        return $this->result(
            match (true) {
                $usedPercent >= 95 => 'critical',
                $usedPercent >= 85 => 'warning',
                default            => 'healthy',
            },
            sprintf('%.1f%% of the disk is in use.', $usedPercent),
            [
                'free'         => \App\Core\Support\Str::bytes((float) $free),
                'total'        => \App\Core\Support\Str::bytes((float) $total),
                'used_percent' => $usedPercent,
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkLogWritability(): array
    {
        $directories = [
            'storage/logs/audit', 'storage/logs/errors', 'storage/logs/security',
            'storage/logs/api', 'storage/logs/system', 'storage/temp', 'public/uploads',
        ];

        $unwritable = [];

        foreach ($directories as $directory) {
            $path = base_path($directory);

            if (!is_dir($path) || !is_writable($path)) {
                $unwritable[] = $directory;
            }
        }

        return $this->result(
            $unwritable === [] ? 'healthy' : 'critical',
            $unwritable === []
                ? 'All runtime directories are writable.'
                : sprintf('%d directory/directories are not writable.', count($unwritable)),
            ['unwritable' => $unwritable]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkDevices(): array
    {
        $counts = $this->devices->connectivityCounts();
        $down   = $counts['offline'] + $counts['never_seen'];

        return $this->result(
            match (true) {
                // Every station down means no monitoring is happening at all.
                $counts['total'] > 0 && $counts['online'] === 0 => 'critical',
                $down > 0                                       => 'warning',
                default                                         => 'healthy',
            },
            sprintf('%d of %d station(s) are reporting.', $counts['online'], $counts['total']),
            $counts
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkPerformance(): array
    {
        $since       = now()->modify('-24 hours')->format('Y-m-d H:i:s');
        $performance = $this->apiLogs->performanceSince($since);

        $failureRate = $performance['total'] === 0
            ? 0.0
            : round(($performance['failed'] / $performance['total']) * 100, 2);

        return $this->result(
            match (true) {
                $failureRate >= 25 || $performance['average_ms'] >= 2000 => 'critical',
                $failureRate >= 10 || $performance['average_ms'] >= 1000 => 'warning',
                default                                                  => 'healthy',
            },
            sprintf(
                '%d request(s) in 24 hours, %.1f%% failed, %.0f ms average.',
                $performance['total'],
                $failureRate,
                $performance['average_ms']
            ),
            array_merge($performance, ['failure_rate' => $failureRate])
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkErrors(): array
    {
        $unresolved = $this->errorLogs->countUnresolved();
        $today      = $this->errorLogs->countSince(now()->format('Y-m-d 00:00:00'));

        return $this->result(
            match (true) {
                $today >= 50     => 'critical',
                $unresolved > 0  => 'warning',
                default          => 'healthy',
            },
            sprintf('%d unresolved error(s); %d recorded today.', $unresolved, $today),
            ['unresolved' => $unresolved, 'today' => $today]
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkSecurity(): array
    {
        $unresolved = $this->securityEvents->countUnresolved();
        $severities = $this->securityEvents->severityCounts(now()->modify('-24 hours')->format('Y-m-d H:i:s'));

        return $this->result(
            match (true) {
                $severities['critical'] > 0 => 'critical',
                $severities['high'] > 0     => 'warning',
                default                     => 'healthy',
            },
            sprintf(
                '%d event(s) awaiting review; %d critical in the last 24 hours.',
                $unresolved,
                $severities['critical']
            ),
            array_merge($severities, ['unresolved' => $unresolved])
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function checkBackups(): array
    {
        try {
            $latest = $this->connection->selectOne(
                "SELECT `filename`, `created_at`, `status`, `file_size`
                   FROM `backup_history`
                  WHERE `status` IN ('completed', 'verified')
                  ORDER BY `created_at` DESC
                  LIMIT 1"
            );
        } catch (Throwable) {
            return $this->result('unknown', 'Backup history is unavailable.', []);
        }

        if ($latest === null) {
            return $this->result('warning', 'No successful backup has ever been recorded.', []);
        }

        $age = time() - (int) strtotime((string) $latest['created_at']);
        $days = (int) floor($age / 86400);

        return $this->result(
            match (true) {
                // A backup older than a week means a failure has gone unnoticed.
                $days >= 7 => 'critical',
                $days >= 2 => 'warning',
                default    => 'healthy',
            },
            sprintf('The most recent backup is %s old.', \App\Core\Support\Str::duration($age)),
            [
                'filename'   => (string) $latest['filename'],
                'created_at' => (string) $latest['created_at'],
                'size'       => \App\Core\Support\Str::bytes((float) $latest['file_size']),
                'age_days'   => $days,
            ]
        );
    }

    /**
     * Runtime facts about the deployment.
     *
     * @return array<string,mixed>
     */
    public function environment(): array
    {
        return [
            'application_version' => (string) config('app.version', '1.0.0'),
            'environment'         => (string) config('app.env', 'production'),
            'debug_mode'          => (bool) config('app.debug', false),
            'php_version'         => PHP_VERSION,
            'database_version'    => $this->connection->serverVersion(),
            'server_software'     => (string) ($_SERVER['SERVER_SOFTWARE'] ?? 'CLI'),
            'timezone'            => (string) config('app.timezone', 'UTC'),
            'memory_limit'        => (string) ini_get('memory_limit'),
            'max_upload'          => (string) ini_get('upload_max_filesize'),
            'active_sessions'     => $this->sessions->countActive(),
            'pending_notifications' => $this->notifications->countPending(),
            'https_enforced'      => (bool) config('security.transport.force_https', true),
            'maintenance_mode'    => (bool) config('app.maintenance.enabled', false),
        ];
    }

    /**
     * @param array<string,mixed> $detail
     *
     * @return array{state:string,message:string,detail:array<string,mixed>}
     */
    private function result(string $state, string $message, array $detail): array
    {
        return ['state' => $state, 'message' => $message, 'detail' => $detail];
    }

    /**
     * A compact answer for an uptime probe.
     *
     * @return array{status:string,database:bool,timestamp:string}
     */
    public function liveness(): array
    {
        $database = false;

        try {
            $database = $this->connection->isHealthy();
        } catch (Throwable) {
            $database = false;
        }

        return [
            'status'    => $database ? 'ok' : 'degraded',
            'database'  => $database,
            'timestamp' => now()->format(DATE_ATOM),
        ];
    }
}
