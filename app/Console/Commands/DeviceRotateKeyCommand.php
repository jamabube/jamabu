<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Repositories\DeviceRepository;
use App\Services\DeviceService;
use Throwable;

/**
 * Issue a new API key for a station.
 *
 * The previous key stops working the moment this runs, so the station is out
 * of service until it is reflashed with the new one. That is the point — it
 * is what a rotation is for after a key has been exposed — but it means the
 * command confirms before acting unless --force says otherwise.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class DeviceRotateKeyCommand extends Command
{
    protected string $name = 'device:rotate-key';
    protected string $description = 'Issue a new API key for a station, invalidating the old one.';
    protected string $usage = 'php bin/console device:rotate-key <device-code|device-id> [--force]';

    public function handle(): int
    {
        $identifier = (string) ($this->argument(0) ?? '');

        if ($identifier === '') {
            $this->output->error('Name the station: php bin/console device:rotate-key ESP32-ENTRY-01');

            return 1;
        }

        $devices = $this->service(DeviceRepository::class);

        $device = ctype_digit($identifier)
            ? $devices->find((int) $identifier)
            : $devices->findByCode($identifier);

        if ($device === null) {
            $this->output->error(sprintf('No station matches "%s".', $identifier));

            return 1;
        }

        $deviceId   = (int) $device['device_id'];
        $deviceCode = (string) $device['device_code'];

        $this->output->title('Rotate the API key for ' . $deviceCode);
        $this->output->table(
            ['Field', 'Value'],
            [
                ['Name', (string) $device['device_name']],
                ['Gate type', (string) $device['gate_type']],
                ['Status', (string) $device['status']],
                ['Key prefix', (string) $device['api_key_prefix'] . '…'],
                ['Issued', (string) $device['api_key_issued_at']],
                ['Last rotated', (string) ($device['api_key_rotated_at'] ?? 'never')],
                ['Last heard from', (string) ($device['last_communication_at'] ?? 'never')],
            ]
        );

        $this->output->warning('The station will stop being able to report until it is reflashed.');

        if (!$this->isForced() && !$this->output->confirm('Rotate the key now?')) {
            $this->output->comment('The key was left as it is.');

            return 0;
        }

        try {
            $key = $this->service(DeviceService::class)->rotateApiKey($deviceId, null);
        } catch (Throwable $e) {
            $this->output->error($e->getMessage());

            return 1;
        }

        $this->output->success(sprintf('A new key was issued for %s.', $deviceCode));
        $this->output->line();
        $this->output->info('API key: ' . $this->output->colour($key, 'bold'));
        $this->output->line();
        $this->output->warning('Shown once. Only its hash is stored.');
        $this->output->comment('Update DEVICE_API_KEY in secrets.h and reflash the station.');

        return 0;
    }
}
