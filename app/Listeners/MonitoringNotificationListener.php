<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Core\Support\Str;
use App\Events\AccessDenied;
use App\Events\VehicleEntered;
use App\Events\VehicleExited;
use App\Services\NotificationService;

/**
 * Turns monitoring events into notifications.
 *
 * Keeping this out of AccessMonitoringService is what lets the decision engine
 * stay a decision engine: it announces what happened, and this decides who
 * should be told.
 *
 * @package App\Listeners
 * @version 1.0.0
 */
final class MonitoringNotificationListener
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function onVehicleEntered(VehicleEntered $event): void
    {
        $subject = $event->isVisitor
            ? sprintf('Visitor %s', $event->ownerName)
            : sprintf('%s (%s)', $event->plateNumber, $event->ownerName);

        $this->notifications->raise('vehicle.entered', [
            'title'        => 'Vehicle entered',
            'description'  => sprintf('%s entered through %s.', $subject, $event->deviceCode),
            'link'         => '/monitoring/records/' . $event->accessLogId,
            'related_type' => 'vehicle_access_logs',
            'related_id'   => $event->accessLogId,
            'metadata'     => ['plate_number' => $event->plateNumber, 'device' => $event->deviceCode],
        ]);
    }

    public function onVehicleExited(VehicleExited $event): void
    {
        $subject = $event->isVisitor
            ? sprintf('Visitor %s', $event->ownerName)
            : sprintf('%s (%s)', $event->plateNumber, $event->ownerName);

        $this->notifications->raise('vehicle.exited', [
            'title'        => 'Vehicle exited',
            'description'  => sprintf(
                '%s exited through %s after %s.',
                $subject,
                $event->deviceCode,
                Str::duration($event->durationSeconds)
            ),
            'link'         => '/monitoring/records/' . $event->accessLogId,
            'related_type' => 'vehicle_access_logs',
            'related_id'   => $event->accessLogId,
            'metadata'     => [
                'plate_number'     => $event->plateNumber,
                'duration_seconds' => $event->durationSeconds,
            ],
        ]);
    }

    public function onAccessDenied(AccessDenied $event): void
    {
        // The notification type is chosen by what actually went wrong, so an
        // unknown tag reaches the people who investigate unknown tags rather
        // than being lumped in with an ordinary policy refusal.
        $typeKey = match ($event->reasonCode) {
            'denied_unknown_tag'       => 'rfid.unknown',
            'denied_expired_tag'       => 'rfid.expired',
            'denied_inactive_vehicle',
            'denied_suspended_vehicle' => 'vehicle.inactive',
            'denied_visitor_expired'   => 'visitor.expired',
            default                    => 'vehicle.rejected',
        };

        $this->notifications->raise($typeKey, [
            'title'       => 'Access refused',
            'description' => sprintf(
                '%s at %s%s',
                $event->message,
                $event->deviceCode,
                $event->plateNumber === null ? '' : sprintf(' (%s)', $event->plateNumber)
            ),
            'link'        => '/monitoring/denials',
            'metadata'    => [
                'reason_code' => $event->reasonCode,
                'rfid_uid'    => $event->rfidUid,
                'device'      => $event->deviceCode,
            ],
        ]);
    }

    /**
     * Dispatch by event type, so one listener can serve the whole module.
     */
    public function handle(object $event): void
    {
        match (true) {
            $event instanceof VehicleEntered => $this->onVehicleEntered($event),
            $event instanceof VehicleExited  => $this->onVehicleExited($event),
            $event instanceof AccessDenied   => $this->onAccessDenied($event),
            default                          => null,
        };
    }

    public function __invoke(object $event): void
    {
        $this->handle($event);
    }
}
