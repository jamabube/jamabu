<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\NotificationRepository;
use App\Repositories\UserRepository;
use App\Core\Database\Connection;
use Throwable;

/**
 * Creates and delivers notifications.
 *
 * The audience for each type is resolved from the notification_types table when
 * an administrator has tuned it, falling back to the configuration defaults.
 * Delivery failure is contained: a notification that cannot be created must
 * never roll back the operation that raised it.
 *
 * @package App\Services
 * @version 1.0.0
 */
class NotificationService
{
    /** @var array<string,array<string,mixed>>|null Cached type definitions. */
    private ?array $types = null;

    public function __construct(
        private readonly NotificationRepository $notifications,
        private readonly UserRepository $users,
        private readonly Connection $connection
    ) {
    }

    /**
     * Raise a notification of a given type.
     *
     * @param array<string,mixed> $context title, description, link, icon,
     *                                     related_type, related_id, metadata,
     *                                     priority, recipients
     *
     * @return int Number of recipients it reached.
     */
    public function raise(string $typeKey, array $context = []): int
    {
        try {
            $definition = $this->definitionFor($typeKey);

            if (!(bool) ($definition['enabled'] ?? true)) {
                return 0;
            }

            /** @var list<int> $recipients */
            $recipients = isset($context['recipients']) && is_array($context['recipients'])
                ? array_map(intval(...), $context['recipients'])
                : $this->audienceFor($typeKey, $definition);

            if ($recipients === []) {
                return 0;
            }

            $metadata = $context['metadata'] ?? null;

            $delivered = $this->notifications->deliverToMany($recipients, [
                'type_key'     => $typeKey,
                'title'        => (string) ($context['title'] ?? $this->defaultTitle($typeKey)),
                'description'  => (string) ($context['description'] ?? ''),
                'priority'     => (string) ($context['priority'] ?? $definition['priority'] ?? 'normal'),
                'link'         => isset($context['link']) ? mb_substr((string) $context['link'], 0, 255) : null,
                'icon'         => isset($context['icon']) ? (string) $context['icon'] : $this->defaultIcon($typeKey),
                'related_type' => isset($context['related_type']) ? (string) $context['related_type'] : null,
                'related_id'   => isset($context['related_id']) ? (string) $context['related_id'] : null,
                'metadata'     => is_array($metadata) ? json_encode($metadata) : null,
                'created_at'   => now()->format('Y-m-d H:i:s'),
            ]);

            return $delivered;
        } catch (Throwable $e) {
            logger()->channel('application')->error('Notification could not be delivered', [
                'type'   => $typeKey,
                'reason' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Resolve who should receive a notification type.
     *
     * @param array<string,mixed> $definition
     *
     * @return list<int>
     */
    private function audienceFor(string $typeKey, array $definition): array
    {
        /** @var list<string> $roles */
        $roles = $definition['roles'] ?? [];

        $recipients = $roles === []
            // An empty audience means "whoever can act on this", which is the
            // people holding the permission for the module it concerns.
            ? $this->users->withPermission($this->permissionFor($typeKey))
            : $this->users->withRoles($roles);

        return array_map(static fn (array $row): int => (int) $row['user_id'], $recipients);
    }

    /**
     * The permission that corresponds to a notification type's module.
     */
    private function permissionFor(string $typeKey): string
    {
        $module = strstr($typeKey, '.', true);

        return match ($module) {
            'vehicle', 'rfid' => 'monitoring.view',
            'device'          => 'devices.view',
            'security'        => 'security.view',
            'backup'          => 'backup.view',
            'user'            => 'users.view',
            'system'          => 'errors.view',
            'visitor'         => 'visitors.view',
            'fingerprint'     => 'fingerprints.view',
            default           => 'notifications.view',
        };
    }

    /**
     * Merge the database definition over the configuration default.
     *
     * @return array<string,mixed>
     */
    private function definitionFor(string $typeKey): array
    {
        $this->types ??= $this->loadTypes();

        if (isset($this->types[$typeKey])) {
            return $this->types[$typeKey];
        }

        /** @var array<string,mixed> $configured */
        $configured = (array) config('notifications.types.' . $typeKey, []);

        return [
            'priority' => (string) ($configured['priority'] ?? 'normal'),
            'roles'    => (array) ($configured['roles'] ?? []),
            'enabled'  => true,
        ];
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    private function loadTypes(): array
    {
        try {
            $rows = $this->connection->select(
                'SELECT `type_key`, `default_priority`, `audience_roles`, `is_enabled` FROM `notification_types`'
            );
        } catch (Throwable) {
            // Before the reference seeder has run, fall back to configuration.
            return [];
        }

        $types = [];

        foreach ($rows as $row) {
            $roles = trim((string) $row['audience_roles']);

            $types[(string) $row['type_key']] = [
                'priority' => (string) $row['default_priority'],
                'roles'    => $roles === '' ? [] : array_map('trim', explode(',', $roles)),
                'enabled'  => (int) $row['is_enabled'] === 1,
            ];
        }

        return $types;
    }

    private function defaultTitle(string $typeKey): string
    {
        return ucwords(str_replace(['.', '_'], ' ', $typeKey));
    }

    /**
     * A sensible icon per type, so a notification is recognisable at a glance.
     */
    private function defaultIcon(string $typeKey): string
    {
        return match ($typeKey) {
            'vehicle.entered'       => 'fa-right-to-bracket',
            'vehicle.exited'        => 'fa-right-from-bracket',
            'vehicle.rejected',
            'rfid.unknown'          => 'fa-ban',
            'rfid.expired'          => 'fa-hourglass-end',
            'vehicle.inactive'      => 'fa-car-burst',
            'device.offline'        => 'fa-plug-circle-xmark',
            'device.online'         => 'fa-plug-circle-check',
            'device.unknown'        => 'fa-microchip',
            'device.registered'     => 'fa-circle-plus',
            'security.alert',
            'security.flood',
            'security.replay'       => 'fa-shield-halved',
            'security.lockout'      => 'fa-lock',
            'fingerprint.failed'    => 'fa-fingerprint',
            'system.error'          => 'fa-triangle-exclamation',
            'backup.completed'      => 'fa-database',
            'backup.failed'         => 'fa-circle-exclamation',
            'user.created'          => 'fa-user-plus',
            'user.password_changed' => 'fa-key',
            'visitor.expired'       => 'fa-user-clock',
            default                 => 'fa-bell',
        };
    }

    // ------------------------------------------------------------------
    // Inbox operations
    // ------------------------------------------------------------------

    /**
     * @return list<array<string,mixed>>
     */
    public function recentFor(int $userId, int $limit = 10): array
    {
        return $this->notifications->recentFor($userId, $limit);
    }

    public function unreadCount(int $userId): int
    {
        return $this->notifications->unreadCount($userId);
    }

    /**
     * @return array<string,int>
     */
    public function unreadByPriority(int $userId): array
    {
        return $this->notifications->unreadByPriority($userId);
    }

    public function markRead(int $notificationId, int $userId): bool
    {
        return $this->notifications->markRead($notificationId, $userId) > 0;
    }

    public function markUnread(int $notificationId, int $userId): bool
    {
        return $this->notifications->markUnread($notificationId, $userId) > 0;
    }

    public function markAllRead(int $userId): int
    {
        return $this->notifications->markAllRead($userId);
    }

    public function archive(int $notificationId, int $userId): bool
    {
        return $this->notifications->archive($notificationId, $userId) > 0;
    }

    public function delete(int $notificationId, int $userId): bool
    {
        return $this->notifications->deleteFor($notificationId, $userId) > 0;
    }

    /**
     * Discard the cached type definitions after an administrator edits them.
     */
    public function flushTypeCache(): void
    {
        $this->types = null;
    }
}
