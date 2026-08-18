<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Session;
use App\DTO\AuthenticatedUser;

/**
 * The authenticated principal for the current request.
 *
 * Holds the signed-in user together with a cached permission set, so an
 * authorisation check costs an array lookup rather than a query. The cache is
 * rebuilt whenever the user's role or permissions change, because a stale
 * permission cache is a privilege-escalation bug.
 *
 * @package App\Core\Security
 * @version 1.0.0
 */
class AuthGuard
{
    private ?AuthenticatedUser $user = null;

    /** @var array<string,true> Permission name => true, for O(1) checks. */
    private array $permissions = [];

    /** The device authenticated for this request, when it is a device call. */
    private ?int $deviceId = null;

    private ?string $deviceCode = null;

    public function __construct(private readonly Session $session)
    {
    }

    /**
     * Bind the authenticated user for this request.
     *
     * @param list<string> $permissions
     */
    public function setUser(AuthenticatedUser $user, array $permissions): void
    {
        $this->user        = $user;
        $this->permissions = array_fill_keys($permissions, true);
    }

    public function clear(): void
    {
        $this->user        = null;
        $this->permissions = [];
    }

    public function check(): bool
    {
        return $this->user !== null;
    }

    public function guest(): bool
    {
        return $this->user === null;
    }

    public function user(): ?AuthenticatedUser
    {
        return $this->user;
    }

    public function id(): ?int
    {
        return $this->user?->id;
    }

    public function username(): string
    {
        return $this->user?->username ?? 'guest';
    }

    public function displayName(): string
    {
        return $this->user?->fullName ?? 'Guest';
    }

    public function roleName(): string
    {
        return $this->user?->roleName ?? '';
    }

    public function roleSlug(): string
    {
        return $this->user?->roleSlug ?? '';
    }

    /**
     * Whether the principal holds a permission.
     *
     * The wildcard permission "*" is granted only to the built-in system
     * administrator role and short-circuits every check.
     */
    public function can(string $permission): bool
    {
        if ($this->user === null) {
            return false;
        }

        if (isset($this->permissions['*'])) {
            return true;
        }

        if (isset($this->permissions[$permission])) {
            return true;
        }

        // A module-level grant ("vehicles.*") covers every action in it.
        $module = strstr($permission, '.', true);

        return $module !== false && isset($this->permissions[$module . '.*']);
    }

    public function cannot(string $permission): bool
    {
        return !$this->can($permission);
    }

    /**
     * Whether the principal holds every listed permission.
     *
     * @param list<string> $permissions
     */
    public function canAll(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (!$this->can($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the principal holds at least one of the listed permissions.
     *
     * @param list<string> $permissions
     */
    public function canAny(array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($permission)) {
                return true;
            }
        }

        return false;
    }

    public function hasRole(string $slug): bool
    {
        return $this->user !== null && $this->user->roleSlug === $slug;
    }

    /**
     * @return list<string>
     */
    public function permissions(): array
    {
        return array_keys($this->permissions);
    }

    // ------------------------------------------------------------------
    // Device principal
    // ------------------------------------------------------------------

    /**
     * Bind the ESP32 device authenticated for this request.
     */
    public function setDevice(int $deviceId, string $deviceCode): void
    {
        $this->deviceId   = $deviceId;
        $this->deviceCode = $deviceCode;
    }

    public function deviceId(): ?int
    {
        return $this->deviceId;
    }

    public function deviceCode(): ?string
    {
        return $this->deviceCode;
    }

    public function isDevice(): bool
    {
        return $this->deviceId !== null;
    }

    /**
     * A short label identifying the actor behind the current request, used in
     * audit records where either a user or a device may be responsible.
     */
    public function actorLabel(): string
    {
        if ($this->user !== null) {
            return $this->user->username;
        }

        if ($this->deviceCode !== null) {
            return 'device:' . $this->deviceCode;
        }

        return 'anonymous';
    }

    public function session(): Session
    {
        return $this->session;
    }
}
