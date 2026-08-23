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

    /**
     * Set when the process is a console command rather than a request.
     *
     * Holds the operating-system account that invoked it, which is the only
     * identity a shell has to offer.
     */
    private ?string $consoleActor = null;

    /**
     * Raised only inside withSystemAuthority(). See can().
     */
    private bool $systemAuthority = false;

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
        // Set only for the duration of a withSystemAuthority() call, which is
        // how a console command performs an action RBAC would otherwise refuse
        // for want of a signed-in user. It is never set by a request.
        if ($this->systemAuthority) {
            return true;
        }

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

        if ($this->consoleActor !== null) {
            return 'console:' . $this->consoleActor;
        }

        return 'anonymous';
    }

    // ------------------------------------------------------------------
    // Console principal
    // ------------------------------------------------------------------

    /**
     * Mark this process as a console command run by the named account.
     *
     * Only the console kernel calls this. It is what lets an audit record say
     * that a device key was rotated from the command line, rather than
     * attributing the action to nobody at all.
     */
    public function actAsConsole(string $actor): void
    {
        $this->consoleActor = trim($actor) === '' ? 'unknown' : trim($actor);
    }

    public function consoleActor(): ?string
    {
        return $this->consoleActor;
    }

    public function isConsole(): bool
    {
        return $this->consoleActor !== null;
    }

    /**
     * Run a callback with every permission granted.
     *
     * A console command has no signed-in user, so a service that asks "may
     * this actor do that?" would otherwise refuse everything — including the
     * account recovery the console exists to provide. Whoever reached a shell
     * on the server already holds more authority over the installation than
     * any role confers, so granting it for the duration of one call takes
     * nothing away.
     *
     * It is scoped rather than a mode: the elevation is dropped even if the
     * callback throws, so nothing later in the process inherits it. Only a
     * console command may call this, and only around the specific action that
     * needs it.
     *
     * @template T
     * @param callable():T $callback
     *
     * @return T
     */
    public function withSystemAuthority(callable $callback): mixed
    {
        if ($this->consoleActor === null) {
            throw new \LogicException('System authority may only be taken by a console command.');
        }

        $previous = $this->systemAuthority;
        $this->systemAuthority = true;

        try {
            return $callback();
        } finally {
            $this->systemAuthority = $previous;
        }
    }

    public function session(): Session
    {
        return $this->session;
    }
}
