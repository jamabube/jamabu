<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Repositories\DeviceRepository;
use App\Repositories\OperatorSessionRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserSessionRepository;
use App\Services\DeviceService;
use App\Services\RegistryService;
use App\Services\VisitorService;
use Throwable;

/**
 * The periodic housekeeping pass.
 *
 * Everything here is a state change that ought to have happened at a moment
 * nobody was watching: a tag that expired at midnight, a shift that ran past
 * its end, a station that stopped answering. Without this running, the system
 * shows yesterday's truth until somebody opens the relevant page.
 *
 * Intended for the scheduler, every few minutes. Each task is independent and
 * a failure in one does not stop the rest — a broken tag expiry must not
 * prevent an offline station from being noticed.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class MaintenanceRunCommand extends Command
{
    protected string $name = 'maintenance:run';
    protected string $description = 'Expire lapsed records, release expired locks and detect offline stations.';
    protected string $usage = 'php bin/console maintenance:run [--quiet-when-idle]';

    public function handle(): int
    {
        $rows     = [];
        $failures = 0;
        $changes  = 0;

        foreach ($this->tasks() as $label => $task) {
            try {
                $count = $task();
                $changes += $count;

                $rows[] = [
                    $label,
                    $count === 0 ? '—' : number_format($count),
                    $count === 0
                        ? 'nothing to do'
                        : $this->output->colour('applied', 'green'),
                ];
            } catch (Throwable $e) {
                $failures++;

                $rows[] = [$label, '—', $this->output->colour('failed: ' . $e->getMessage(), 'red')];

                logger()->channel('application')->error('A maintenance task failed', [
                    'task'   => $label,
                    'reason' => $e->getMessage(),
                ]);
            }
        }

        // A scheduler that mails its output should stay quiet on the many
        // passes where there was simply nothing to do.
        if ($changes === 0 && $failures === 0 && $this->hasOption('quiet-when-idle')) {
            return 0;
        }

        $this->output->title('Maintenance');
        $this->output->table(['Task', 'Records', 'Result'], $rows);

        if ($failures > 0) {
            $this->output->error(sprintf('%d task(s) failed. The rest completed.', $failures));

            return 1;
        }

        if ($changes === 0) {
            $this->output->info('Nothing needed doing.');

            return 0;
        }

        $this->output->success(sprintf('%s record(s) brought up to date.', number_format($changes)));

        return 0;
    }

    /**
     * The housekeeping tasks, in the order they should run.
     *
     * Order matters in one place: stations are checked after operator shifts
     * are expired, so a shift that ended on its own is not also reported as
     * having been cut short by an outage.
     *
     * @return array<string,callable():int>
     */
    private function tasks(): array
    {
        return [
            'Expire overdue RFID tags' =>
                fn (): int => $this->service(RegistryService::class)->expireOverdueTags(),

            'Expire overdue visitor passes' =>
                fn (): int => $this->service(VisitorService::class)->expireOverduePasses(),

            'End operator shifts past their expiry' =>
                fn (): int => $this->service(OperatorSessionRepository::class)->expireOverdue(),

            'Close abandoned browser sessions' =>
                fn (): int => $this->service(UserSessionRepository::class)->closeExpired(),

            'Release expired account locks' =>
                fn (): int => $this->service(UserRepository::class)->releaseExpiredLocks(),

            'Release expired station suspensions' =>
                fn (): int => $this->service(DeviceRepository::class)->releaseExpiredSuspensions(),

            'Detect stations that stopped reporting' =>
                fn (): int => count($this->service(DeviceService::class)->detectOfflineDevices()),

            'Recalculate station health scores' =>
                fn (): int => $this->recalculateHealth(),
        ];
    }

    /**
     * Recompute every station's health score.
     *
     * @return int The number of stations whose score changed, which is what
     *             the report should show — recomputing an unchanged score is
     *             not a record brought up to date.
     */
    private function recalculateHealth(): int
    {
        $devices = $this->service(DeviceService::class);
        $changed = 0;

        foreach ($devices->allWithStatus() as $device) {
            $before = (int) ($device['health_score'] ?? 0);
            $after  = $devices->calculateHealthScore((int) $device['device_id']);

            if ($before !== $after) {
                $changed++;
            }
        }

        return $changed;
    }
}
