<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\SecurityAlertRaised;
use App\Services\NotificationService;

/**
 * Notifies administrators of high and critical security events.
 *
 * @package App\Listeners
 * @version 1.0.0
 */
final class SecurityNotificationListener
{
    public function __construct(private readonly NotificationService $notifications)
    {
    }

    public function handle(SecurityAlertRaised $event): void
    {
        // The specific types get their own notification so a flood or a replay
        // is recognisable in the inbox without opening it.
        $typeKey = match ($event->eventType) {
            'flood_detected'  => 'security.flood',
            'replay_attack'   => 'security.replay',
            'account_locked'  => 'security.lockout',
            'unknown_device'  => 'device.unknown',
            'fingerprint_failure' => 'fingerprint.failed',
            default           => 'security.alert',
        };

        $this->notifications->raise($typeKey, [
            'title'        => sprintf('%s security event', ucfirst($event->severity)),
            'description'  => $event->description,
            'priority'     => $event->severity === 'critical' ? 'critical' : 'high',
            'link'         => $event->eventId === null ? '/security' : '/security/' . $event->eventId,
            'related_type' => 'security_events',
            'related_id'   => $event->eventId,
            'metadata'     => ['event_type' => $event->eventType, 'severity' => $event->severity],
        ]);
    }

    public function __invoke(SecurityAlertRaised $event): void
    {
        $this->handle($event);
    }
}
