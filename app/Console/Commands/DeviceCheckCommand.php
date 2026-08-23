<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Services\DeviceService;

/**
 * Report on the gate stations: connectivity, health and last contact.
 *
 * Written to be readable over a slow SSH session at two in the morning, which
 * is when somebody is most likely to be running it, and to exit non-zero when
 * a station is down so a scheduler can act on the result.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class DeviceCheckCommand extends Command
{
    protected string $name = 'device:check';
    protected string $description = 'Report station connectivity and health; exits 1 when a station is offline.';
    protected string $usage = 'php bin/console device:check [--recalculate] [--quiet-when-healthy]';

    public function handle(): int
    {
        $devices = $this->service(DeviceService::class);

        // Scores age: they are computed when a heartbeat lands, so a station
        // that stopped reporting keeps the score it had when it fell over.
        // --recalculate forces the arithmetic to run again before reporting.
        if ($this->hasOption('recalculate')) {
            foreach ($devices->allWithStatus() as $device) {
                $devices->calculateHealthScore((int) $device['device_id']);
            }
        }

        // Raises the notifications and closes the operator shifts belonging to
        // stations that have gone quiet, so running this from a scheduler is
        // what makes an outage visible in the interface.
        $offline = $devices->detectOfflineDevices();

        $rows       = [];
        $unhealthy  = 0;
        $registered = 0;

        foreach ($devices->allWithStatus() as $device) {
            $registered++;

            $score        = (int) ($device['health_score'] ?? 0);
            $connectivity = (string) ($device['connectivity'] ?? 'unknown');
            $band         = $devices->healthBand($score);

            if ($connectivity !== 'online' || $band === 'poor' || $band === 'critical') {
                $unhealthy++;
            }

            $rows[] = [
                (string) $device['device_code'],
                (string) $device['gate_type'],
                $this->colourConnectivity($connectivity),
                $this->colourBand($band, $score),
                $this->age($device['last_communication_at'] ?? null),
                (string) ($device['signal_strength'] ?? '—'),
                (string) $device['error_count'],
                (string) $device['restart_count'],
            ];
        }

        if ($registered === 0) {
            $this->output->warning('No stations are registered. Use device:register to add one.');

            return 0;
        }

        // A scheduler that mails its output only wants to hear about problems.
        if ($unhealthy === 0 && $this->hasOption('quiet-when-healthy')) {
            return 0;
        }

        $this->output->title('Monitoring stations');
        $this->output->table(
            ['Code', 'Gate', 'Link', 'Health', 'Last seen', 'RSSI', 'Errors', 'Restarts'],
            $rows
        );

        if ($offline !== []) {
            $this->output->error(sprintf(
                '%d station(s) have stopped reporting: %s',
                count($offline),
                implode(', ', array_map(
                    static fn (array $device): string => (string) $device['device_code'],
                    $offline
                ))
            ));
        }

        if ($unhealthy === 0) {
            $this->output->success(sprintf('All %d station(s) are reporting and healthy.', $registered));

            return 0;
        }

        $this->output->warning(sprintf('%d of %d station(s) need attention.', $unhealthy, $registered));

        return 1;
    }

    /**
     * How long ago a timestamp was, in the coarsest unit that is still useful.
     */
    private function age(mixed $timestamp): string
    {
        if (!is_string($timestamp) || $timestamp === '') {
            return 'never';
        }

        $seconds = time() - (int) strtotime($timestamp);

        if ($seconds < 0) {
            // A station whose clock runs ahead of the server's; the reading is
            // not useful, but saying so beats printing a negative age.
            return 'in the future';
        }

        return match (true) {
            $seconds < 90      => $seconds . 's ago',
            $seconds < 5400    => (int) round($seconds / 60) . 'm ago',
            $seconds < 172800  => (int) round($seconds / 3600) . 'h ago',
            default            => (int) round($seconds / 86400) . 'd ago',
        };
    }

    private function colourConnectivity(string $connectivity): string
    {
        return match ($connectivity) {
            'online'  => $this->output->colour('online', 'green'),
            'delayed' => $this->output->colour('delayed', 'yellow'),
            default   => $this->output->colour($connectivity, 'red'),
        };
    }

    private function colourBand(string $band, int $score): string
    {
        $text = sprintf('%3d %s', $score, $band);

        return match ($band) {
            'excellent', 'good' => $this->output->colour($text, 'green'),
            'fair'              => $this->output->colour($text, 'yellow'),
            default             => $this->output->colour($text, 'red'),
        };
    }
}
