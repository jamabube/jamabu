<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Security\AuthGuard;
use App\Repositories\AccessDenialRepository;
use App\Repositories\AccessLogRepository;
use App\Repositories\AuditLogRepository;
use App\Repositories\DeviceRepository;
use App\Repositories\DriverRepository;
use App\Repositories\ErrorLogRepository;
use App\Repositories\NotificationRepository;
use App\Repositories\RfidTagRepository;
use App\Repositories\SecurityEventRepository;
use App\Repositories\UserSessionRepository;
use App\Repositories\VehicleRepository;
use App\Repositories\VisitorLogRepository;

/**
 * Assembles the dashboard.
 *
 * Every figure is scoped to what the signed-in user may see, so a guard's
 * dashboard does not silently disclose counts from modules they cannot open.
 *
 * @package App\Services
 * @version 1.0.0
 */
class DashboardService
{
    public function __construct(
        private readonly AccessLogRepository $accessLogs,
        private readonly AccessDenialRepository $denials,
        private readonly VehicleRepository $vehicles,
        private readonly DriverRepository $drivers,
        private readonly RfidTagRepository $tags,
        private readonly VisitorLogRepository $visitorLogs,
        private readonly DeviceRepository $devices,
        private readonly SecurityEventRepository $securityEvents,
        private readonly ErrorLogRepository $errorLogs,
        private readonly AuditLogRepository $auditLogs,
        private readonly NotificationRepository $notifications,
        private readonly UserSessionRepository $sessions,
        private readonly AuthGuard $auth
    ) {
    }

    /**
     * The summary cards.
     *
     * @return array<string,mixed>
     */
    public function summaryCards(): array
    {
        $dayStart = now()->format('Y-m-d 00:00:00');
        $dayEnd   = now()->format('Y-m-d 23:59:59');

        $cards = [];

        if ($this->auth->can('monitoring.view')) {
            $inside = $this->accessLogs->countInside();

            $cards['entries_today'] = [
                'label' => "Today's entries",
                'value' => $this->accessLogs->countEntriesBetween($dayStart, $dayEnd),
                'icon'  => 'fa-right-to-bracket',
                'tone'  => 'success',
                'link'  => '/monitoring/history?date_from=' . now()->format('Y-m-d'),
            ];

            $cards['exits_today'] = [
                'label' => "Today's exits",
                'value' => $this->accessLogs->countExitsBetween($dayStart, $dayEnd),
                'icon'  => 'fa-right-from-bracket',
                'tone'  => 'primary',
                'link'  => '/monitoring/history?date_from=' . now()->format('Y-m-d'),
            ];

            $cards['inside'] = [
                'label' => 'Vehicles inside',
                'value' => $inside,
                'icon'  => 'fa-warehouse',
                'tone'  => 'info',
                'link'  => '/monitoring/inside',
            ];

            $cards['rejected_today'] = [
                'label' => 'Rejected today',
                'value' => $this->denials->countBetween($dayStart, $dayEnd),
                'icon'  => 'fa-ban',
                'tone'  => 'warning',
                'link'  => '/monitoring/denials',
            ];
        }

        if ($this->auth->can('vehicles.view')) {
            $vehicleCounts = $this->vehicles->statusCounts();

            $cards['registered_vehicles'] = [
                'label' => 'Registered vehicles',
                'value' => $vehicleCounts['active'],
                'icon'  => 'fa-car',
                'tone'  => 'secondary',
                'link'  => '/vehicles',
            ];
        }

        if ($this->auth->can('drivers.view')) {
            $cards['registered_drivers'] = [
                'label' => 'Registered drivers',
                'value' => $this->drivers->statusCounts()['active'],
                'icon'  => 'fa-id-card',
                'tone'  => 'secondary',
                'link'  => '/drivers',
            ];
        }

        if ($this->auth->can('rfid.view')) {
            $tagCounts = $this->tags->statusCounts();

            $cards['rfid_tags'] = [
                'label' => 'Active RFID tags',
                'value' => $tagCounts['assigned'] + $tagCounts['available'],
                'icon'  => 'fa-tags',
                'tone'  => 'secondary',
                'link'  => '/rfid/tags',
            ];
        }

        if ($this->auth->can('visitors.view')) {
            $cards['visitors_inside'] = [
                'label' => 'Visitors inside',
                'value' => $this->visitorLogs->countInside(),
                'icon'  => 'fa-user-clock',
                'tone'  => 'info',
                'link'  => '/visitors/passes?status=inside',
            ];
        }

        if ($this->auth->can('devices.view')) {
            $connectivity = $this->devices->connectivityCounts();

            $cards['devices_online'] = [
                'label' => 'Stations online',
                'value' => $connectivity['online'],
                'icon'  => 'fa-plug-circle-check',
                'tone'  => 'success',
                'link'  => '/devices',
            ];

            $cards['devices_offline'] = [
                'label' => 'Stations offline',
                'value' => $connectivity['offline'] + $connectivity['never_seen'],
                'icon'  => 'fa-plug-circle-xmark',
                'tone'  => $connectivity['offline'] > 0 ? 'danger' : 'secondary',
                'link'  => '/devices?connectivity=offline',
            ];
        }

        if ($this->auth->can('security.view')) {
            $cards['security_today'] = [
                'label' => "Today's security events",
                'value' => $this->securityEvents->countSince($dayStart),
                'icon'  => 'fa-shield-halved',
                'tone'  => 'danger',
                'link'  => '/security',
            ];
        }

        if ($this->auth->can('errors.view')) {
            $cards['errors_today'] = [
                'label' => "Today's errors",
                'value' => $this->errorLogs->countSince($dayStart),
                'icon'  => 'fa-triangle-exclamation',
                'tone'  => 'warning',
                'link'  => '/errors',
            ];
        }

        if ($this->auth->can('audit.view')) {
            $cards['audit_today'] = [
                'label' => "Today's audit records",
                'value' => $this->auditLogs->countSince($dayStart),
                'icon'  => 'fa-clipboard-list',
                'tone'  => 'secondary',
                'link'  => '/audit',
            ];
        }

        if ($this->auth->can('users.sessions')) {
            $cards['active_sessions'] = [
                'label' => 'Active sessions',
                'value' => $this->sessions->countActive(),
                'icon'  => 'fa-users',
                'tone'  => 'secondary',
                'link'  => '/users/sessions',
            ];
        }

        $userId = $this->auth->id();

        if ($userId !== null) {
            $cards['notifications'] = [
                'label' => 'Unread notifications',
                'value' => $this->notifications->unreadCount($userId),
                'icon'  => 'fa-bell',
                'tone'  => 'info',
                'link'  => '/notifications',
            ];
        }

        return $cards;
    }

    /**
     * The live activity feed.
     *
     * @return list<array<string,mixed>>
     */
    public function liveActivity(int $limit = 15, int $sinceId = 0): array
    {
        if ($this->auth->cannot('monitoring.view')) {
            return [];
        }

        return $this->accessLogs->liveFeed($limit, $sinceId);
    }

    /**
     * Chart data for the dashboard.
     *
     * @return array<string,mixed>
     */
    public function charts(): array
    {
        if ($this->auth->cannot('monitoring.view')) {
            return [];
        }

        $today = now()->format('Y-m-d');
        $from  = now()->modify('-13 days')->format('Y-m-d');

        return [
            'hourly' => $this->accessLogs->hourlyBreakdown($today),
            'daily'  => $this->accessLogs->dailySummary($from, $today),
            'by_type'=> $this->auth->can('vehicles.view') ? $this->vehicles->countsByType() : [],
            'denials'=> $this->denials->reasonBreakdown(
                now()->modify('-30 days')->format('Y-m-d 00:00:00'),
                now()->format('Y-m-d 23:59:59')
            ),
        ];
    }

    /**
     * The device status panel.
     *
     * @return list<array<string,mixed>>
     */
    public function deviceStatus(): array
    {
        return $this->auth->can('devices.view') ? $this->devices->allWithStatus() : [];
    }

    /**
     * The security alert panel.
     *
     * @return list<array<string,mixed>>
     */
    public function securityAlerts(int $limit = 6): array
    {
        return $this->auth->can('security.view') ? $this->securityEvents->activeAlerts($limit) : [];
    }

    /**
     * The recent audit panel.
     *
     * @return list<array<string,mixed>>
     */
    public function recentAudit(int $limit = 8): array
    {
        return $this->auth->can('audit.view') ? $this->auditLogs->recent($limit) : [];
    }

    /**
     * Open visits that have outstayed the configured threshold.
     *
     * @return list<array<string,mixed>>
     */
    public function overstaying(): array
    {
        if ($this->auth->cannot('monitoring.view')) {
            return [];
        }

        return $this->accessLogs->overstaying((int) config('monitoring.rules.overstay_alert_hours', 24));
    }

    /**
     * Everything the dashboard needs, in one call.
     *
     * @return array<string,mixed>
     */
    public function assemble(): array
    {
        return [
            'cards'        => $this->summaryCards(),
            'activity'     => $this->liveActivity(),
            'devices'      => $this->deviceStatus(),
            'alerts'       => $this->securityAlerts(),
            'audit'        => $this->recentAudit(),
            'overstaying'  => $this->overstaying(),
            'charts'       => $this->charts(),
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }

    /**
     * The lightweight payload the dashboard polls for.
     *
     * Deliberately excludes the charts, which are expensive and change slowly;
     * refreshing them every few seconds would be wasted work.
     *
     * @return array<string,mixed>
     */
    public function poll(int $sinceId = 0): array
    {
        return [
            'cards'        => $this->summaryCards(),
            'activity'     => $this->liveActivity(10, $sinceId),
            'devices'      => $this->deviceStatus(),
            'alerts'       => $this->securityAlerts(5),
            'generated_at' => now()->format('Y-m-d H:i:s'),
        ];
    }
}
