<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Core\Support\Str;
use App\Events\BackupCompleted;
use App\Events\DeviceCameOnline;
use App\Events\DeviceWentOffline;
use App\Services\NotificationService;

/**
 * Notifies administrators about device availability and backup outcomes.
 *
 * @package App\Listeners
 * @version 1.0.0
 */
final class DeviceNotificationListener
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function onDeviceWentOffline(DeviceWentOffline $event): void
    {
        $this->notifications->raise('device.offline', [
            'title'        => 'Monitoring station offline',
            'description'  => sprintf(
                '%s (%s) has not reported for %s.%s',
                $event->deviceName,
                $event->deviceCode,
                Str::duration($event->secondsSinceHeartbeat),
                $event->location === null ? '' : ' Location: ' . $event->location . '.'
            ),
            'link'         => '/devices/' . $event->deviceId,
            'related_type' => 'devices',
            'related_id'   => $event->deviceId,
            'metadata'     => ['device_code' => $event->deviceCode, 'silent_for' => $event->secondsSinceHeartbeat],
        ]);
    }

    public function onDeviceCameOnline(DeviceCameOnline $event): void
    {
        $this->notifications->raise('device.online', [
            'title'        => 'Monitoring station recovered',
            'description'  => sprintf(
                '%s (%s) is reporting again after %s offline.',
                $event->deviceName,
                $event->deviceCode,
                Str::duration($event->outageSeconds)
            ),
            'link'         => '/devices/' . $event->deviceId,
            'related_type' => 'devices',
            'related_id'   => $event->deviceId,
        ]);
    }

    public function onBackupCompleted(BackupCompleted $event): void
    {
        $this->notifications->raise($event->successful ? 'backup.completed' : 'backup.failed', [
            'title'        => $event->successful ? 'Backup completed' : 'Backup failed',
            'description'  => $event->successful
                ? sprintf('%s was created successfully (%s).', $event->filename, Str::bytes($event->fileSize))
                : sprintf('The backup did not complete: %s', $event->message),
            'link'         => '/backups',
            'related_type' => 'backup_history',
            'related_id'   => $event->backupId,
        ]);
    }

    public function handle(object $event): void
    {
        match (true) {
            $event instanceof DeviceWentOffline => $this->onDeviceWentOffline($event),
            $event instanceof DeviceCameOnline  => $this->onDeviceCameOnline($event),
            $event instanceof BackupCompleted   => $this->onBackupCompleted($event),
            default                             => null,
        };
    }

    public function __invoke(object $event): void
    {
        $this->handle($event);
    }
}
