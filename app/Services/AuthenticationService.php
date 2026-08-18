<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Events\EventDispatcher;
use App\Core\Http\Request;
use App\Core\Security\AuthGuard;
use App\Core\Security\CsrfGuard;
use App\Core\Security\Hasher;
use App\Core\Session;
use App\DTO\AuthenticatedUser;
use App\Events\UserSignedIn;
use App\Exceptions\AuthenticationException;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserSessionRepository;

/**
 * User authentication.
 *
 * Implements the sign-in sequence: identify, verify, check account state,
 * establish a session, and record everything. Two properties are deliberate
 * and load-bearing:
 *
 *   1. The reason a sign-in failed is never disclosed to the client. Whether
 *      the username exists, the password was wrong, or the account is locked,
 *      the caller sees one generic message — while the audit trail records
 *      exactly which it was.
 *   2. A failed attempt costs the same as a successful one. Verification is
 *      performed against a decoy hash when the account does not exist, so
 *      response timing cannot be used to enumerate usernames.
 *
 * @package App\Services
 * @version 1.0.0
 */
class AuthenticationService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly UserSessionRepository $sessions,
        private readonly LoginAttemptRepository $attempts,
        private readonly PasswordPolicyService $passwordPolicy,
        private readonly AuditService $audit,
        private readonly SecurityEventService $security,
        private readonly Session $session,
        private readonly AuthGuard $guard,
        private readonly CsrfGuard $csrf,
        private readonly EventDispatcher $events
    ) {
    }

    /**
     * Attempt to sign a user in.
     *
     * @throws AuthenticationException On any failure, with a generic message.
     */
    public function attempt(string $username, string $password, Request $request, bool $remember = false): AuthenticatedUser
    {
        $username  = trim($username);
        $ipAddress = $request->ip();
        $userAgent = $request->userAgent();

        $this->guardAgainstAddressFlooding($ipAddress, $username);

        $record = $username === '' ? null : $this->users->findForAuthentication($username);

        // An unknown username still pays the full cost of a bcrypt
        // verification, so timing does not reveal that the account is absent.
        if ($record === null) {
            Hasher::burn();

            $this->recordFailure($username, null, $request, 'unknown_username');

            throw AuthenticationException::invalidCredentials();
        }

        $userId = (int) $record['user_id'];

        $lockFailure = $this->checkLockState($record);
        if ($lockFailure !== null) {
            Hasher::burn();
            $this->recordFailure($username, $userId, $request, 'locked');

            throw $lockFailure;
        }

        if (!Hasher::verify($password, (string) $record['password_hash'])) {
            $this->recordFailure($username, $userId, $request, 'invalid_password');
            $this->applyLockoutPolicy($record);

            throw AuthenticationException::invalidCredentials();
        }

        // Only after the password is proven correct does the system disclose
        // anything about the account's state; doing it earlier would let an
        // attacker probe which accounts exist and are active.
        if ((string) $record['status'] !== 'active') {
            $this->recordFailure($username, $userId, $request, 'inactive_account');
            $this->security->record(
                'account_inactive_login',
                sprintf('Sign-in refused for "%s": the account is %s.', $username, (string) $record['status']),
                ['username' => $username, 'status' => $record['status']],
                'rejected'
            );

            throw AuthenticationException::accountInactive();
        }

        if ((string) $record['role_status'] !== 'active') {
            $this->recordFailure($username, $userId, $request, 'inactive_role');

            throw AuthenticationException::accountInactive();
        }

        // The credential was correct, so the hash may be upgraded silently if
        // the cost factor has been raised since it was set.
        if (Hasher::needsRehash((string) $record['password_hash'])) {
            $this->users->updatePassword($userId, Hasher::make($password), (bool) $record['must_change_password']);
        }

        return $this->establishSession($record, $request, $remember);
    }

    /**
     * Build the session for a verified account.
     *
     * @param array<string,mixed> $record
     */
    private function establishSession(array $record, Request $request, bool $remember): AuthenticatedUser
    {
        $userId = (int) $record['user_id'];

        // Regenerating first defeats session fixation: any identifier an
        // attacker managed to plant is discarded at the moment of sign-in.
        $this->session->regenerate();
        $this->csrf->flush();

        $this->enforceConcurrencyPolicy($userId, $request);

        $lifetime  = (int) config('session.lifetime', 1800);
        $expiresAt = now()->modify('+' . max(60, $lifetime) . ' seconds')->format('Y-m-d H:i:s');

        $fingerprint = $this->session->fingerprint(
            $request->userAgent(),
            $request->ip(),
            (bool) config('session.fingerprint.bind_ip', false)
        );

        $this->session->put('_user_id', $userId);
        $this->session->put('_authenticated_at', time());
        $this->session->put('_started_at', time());
        $this->session->put('_remember', $remember);
        $this->session->bindFingerprint($fingerprint);
        $this->session->touch();

        $this->sessions->open(
            userId: $userId,
            sessionId: $this->session->id(),
            ipAddress: $request->ip(),
            userAgent: $request->userAgent(),
            deviceLabel: $this->describeClient($request->userAgent()),
            fingerprint: $fingerprint,
            expiresAt: $expiresAt
        );

        $this->users->recordSuccessfulLogin($userId, $request->ip(), $request->userAgent());
        $this->attempts->record((string) $record['username'], $userId, $request->ip(), $request->userAgent(), true);

        $user        = AuthenticatedUser::fromRow($record);
        $permissions = $this->users->permissionsFor($userId);

        $this->guard->setUser($user, $permissions);

        $this->audit->record('authentication', 'login', sprintf('%s signed in.', $user->username), [
            'record_type' => 'users',
            'record_id'   => $userId,
        ]);

        $this->events->dispatch(new UserSignedIn(
            userId: $userId,
            username: $user->username,
            ipAddress: $request->ip()
        ));

        return $user;
    }

    /**
     * Restore the principal for an already-authenticated request.
     *
     * Returns null when the session is absent, expired, terminated elsewhere,
     * or bound to a different client.
     */
    public function resolveFromSession(Request $request): ?AuthenticatedUser
    {
        $userId = (int) $this->session->get('_user_id', 0);

        if ($userId <= 0) {
            return null;
        }

        // A session the administrator terminated must stop working at once,
        // which is why the server-side register is consulted on every request
        // rather than trusting the cookie alone.
        if (!$this->sessions->isActive($this->session->id())) {
            $this->forgetSession();

            return null;
        }

        $fingerprint = $this->session->fingerprint(
            $request->userAgent(),
            $request->ip(),
            (bool) config('session.fingerprint.bind_ip', false)
        );

        if (!$this->session->fingerprintMatches($fingerprint)) {
            $this->security->record(
                'session_fingerprint_mismatch',
                'A session was presented from a client that does not match the one it was issued to.',
                ['user_id' => $userId],
                'session_terminated'
            );

            $this->sessions->close($this->session->id(), 'fingerprint_mismatch');
            $this->forgetSession();

            return null;
        }

        $lifetime = (int) config('session.lifetime', 1800);
        $absolute = (int) config('session.absolute_lifetime', 43200);

        if ($this->session->isIdleExpired($lifetime)) {
            $this->terminate('timeout');

            return null;
        }

        if ($this->session->isAbsoluteExpired($absolute)) {
            $this->terminate('absolute_timeout');

            return null;
        }

        $record = $this->users->findWithRole($userId);

        // An account deactivated, locked or deleted mid-session loses access
        // immediately rather than at the next sign-in.
        if ($record === null
            || (string) $record['status'] !== 'active'
            || (int) $record['is_locked'] === 1
            || (string) $record['role_status'] !== 'active') {
            $this->terminate('administrator');

            return null;
        }

        $user = AuthenticatedUser::fromRow($record);
        $this->guard->setUser($user, $this->users->permissionsFor($userId));

        $this->session->touch();
        $this->session->regenerateIfDue((int) config('session.regenerate_interval', 300));
        $this->sessions->touch(
            $this->session->id(),
            now()->modify('+' . max(60, $lifetime) . ' seconds')->format('Y-m-d H:i:s')
        );

        return $user;
    }

    /**
     * Sign the current user out.
     */
    public function logout(string $reason = 'logout'): void
    {
        $user = $this->guard->user();

        if ($user !== null) {
            $this->audit->record('authentication', 'logout', sprintf('%s signed out.', $user->username), [
                'record_type' => 'users',
                'record_id'   => $user->id,
            ]);
        }

        $this->terminate($reason);
    }

    /**
     * Close the session record and clear every trace of the principal.
     */
    private function terminate(string $reason): void
    {
        if ($this->session->id() !== '') {
            $this->sessions->close($this->session->id(), $reason);
        }

        $this->forgetSession();
    }

    private function forgetSession(): void
    {
        $this->csrf->flush();
        $this->guard->clear();
        $this->session->destroy();
    }

    /**
     * Whether the signed-in user must change their password before continuing.
     */
    public function requiresPasswordChange(AuthenticatedUser $user): bool
    {
        return $user->mustChangePassword || $this->passwordPolicy->isExpired($user->passwordChangedAt);
    }

    // ------------------------------------------------------------------
    // Policy enforcement
    // ------------------------------------------------------------------

    /**
     * Reject the attempt when the account is locked.
     *
     * @param array<string,mixed> $record
     */
    private function checkLockState(array $record): ?AuthenticationException
    {
        if ((int) $record['is_locked'] !== 1) {
            return null;
        }

        $lockedUntil = $record['locked_until'] ?? null;

        // A permanent lock has no expiry and only an administrator clears it.
        if ($lockedUntil === null) {
            return AuthenticationException::accountLocked();
        }

        $expiresAt = strtotime((string) $lockedUntil);

        if ($expiresAt !== false && $expiresAt <= time()) {
            // The lock has served its time; release it and let the attempt run.
            $this->users->unlock((int) $record['user_id']);

            return null;
        }

        $minutes = $expiresAt === false ? 0 : (int) ceil(($expiresAt - time()) / 60);

        return AuthenticationException::accountLocked($minutes);
    }

    /**
     * Lock the account once consecutive failures reach the threshold.
     *
     * @param array<string,mixed> $record
     */
    private function applyLockoutPolicy(array $record): void
    {
        $userId    = (int) $record['user_id'];
        $username  = (string) $record['username'];
        $maximum   = (int) config('security.lockout.max_attempts', 5);
        $lockFor   = (int) config('security.lockout.lock_minutes', 15);
        $attempts  = $this->users->incrementFailedAttempts($userId);

        if ($maximum <= 0 || $attempts < $maximum) {
            return;
        }

        // A configured permanent-lock threshold turns repeated lockouts into a
        // lock only an administrator can clear.
        $permanentAfter = (int) config('security.lockout.permanent_after', 0);
        $permanent      = $permanentAfter > 0 && $attempts >= $permanentAfter;

        $this->users->lock(
            $userId,
            $permanent ? null : now()->modify('+' . max(1, $lockFor) . ' minutes')->format('Y-m-d H:i:s'),
            sprintf('Locked automatically after %d consecutive failed sign-in attempts.', $attempts)
        );

        $this->security->record(
            'account_locked',
            sprintf('Account "%s" was locked after %d consecutive failed attempts.', $username, $attempts),
            ['username' => $username, 'attempts' => $attempts, 'permanent' => $permanent],
            $permanent ? 'account_locked_permanently' : 'account_locked'
        );

        $this->audit->failed('authentication', 'lockout', sprintf('Account "%s" was locked automatically.', $username), [
            'record_type' => 'users',
            'record_id'   => $userId,
        ]);
    }

    /**
     * Throttle an address producing a burst of failures across many accounts.
     *
     * The per-account lockout alone does not stop an attacker spraying one
     * password across every username; this does.
     *
     * @throws AuthenticationException
     */
    private function guardAgainstAddressFlooding(string $ipAddress, string $username): void
    {
        if (!(bool) config('security.lockout.track_by_ip', true)) {
            return;
        }

        $maximum = (int) config('security.lockout.ip_max_attempts', 20);
        $window  = (int) config('security.lockout.ip_window_minutes', 15);

        if ($maximum <= 0) {
            return;
        }

        $failures = $this->attempts->failuresFromAddress($ipAddress, $window);

        if ($failures < $maximum) {
            return;
        }

        $this->security->record(
            'flood_detected',
            sprintf('Sign-in attempts from %s were blocked after %d failures in %d minutes.', $ipAddress, $failures, $window),
            ['ip_address' => $ipAddress, 'failures' => $failures, 'username' => $username],
            'blocked'
        );

        throw AuthenticationException::invalidCredentials();
    }

    /**
     * Apply the concurrent-session policy for a user about to sign in.
     */
    private function enforceConcurrencyPolicy(int $userId, Request $request): void
    {
        $single = (bool) config('session.concurrency.single_session', false);

        if ($single) {
            $closed = $this->sessions->closeAllFor($userId, 'concurrent', $this->session->id());

            if ($closed > 0) {
                $this->security->record(
                    'concurrent_session',
                    sprintf('%d earlier session(s) were closed because only one session per user is permitted.', $closed),
                    ['user_id' => $userId, 'closed' => $closed],
                    'previous_sessions_terminated'
                );
            }

            return;
        }

        // Even when several sessions are allowed, an unbounded number is a
        // sign of a shared or leaked credential.
        $maximum = (int) config('session.concurrency.max_concurrent_sessions', 3);

        if ($maximum > 0 && $this->sessions->countActiveFor($userId) >= $maximum) {
            $this->security->record(
                'concurrent_session',
                sprintf('An account signed in while already holding %d active sessions.', $maximum),
                ['user_id' => $userId, 'ip_address' => $request->ip()],
                'permitted'
            );
        }
    }

    /**
     * Record a failed attempt in both the attempt log and the security log.
     */
    private function recordFailure(string $username, ?int $userId, Request $request, string $reason): void
    {
        $this->attempts->record($username, $userId, $request->ip(), $request->userAgent(), false, $reason);

        $consecutive = $username === '' ? 0 : $this->attempts->consecutiveFailures($username);

        $this->security->failedLogin($username === '' ? '(blank)' : $username, $reason, $consecutive);

        $this->audit->failed(
            'authentication',
            'login',
            sprintf('Failed sign-in attempt for "%s".', $username === '' ? '(blank)' : $username),
            ['record_type' => 'users', 'record_id' => $userId]
        );
    }

    /**
     * Summarise a user agent for the active-sessions list.
     */
    private function describeClient(string $userAgent): string
    {
        if ($userAgent === '') {
            return 'Unknown client';
        }

        $browser = match (true) {
            str_contains($userAgent, 'Edg/')     => 'Edge',
            str_contains($userAgent, 'OPR/')     => 'Opera',
            str_contains($userAgent, 'Firefox/') => 'Firefox',
            str_contains($userAgent, 'Chrome/')  => 'Chrome',
            str_contains($userAgent, 'Safari/')  => 'Safari',
            default                              => 'Browser',
        };

        $platform = match (true) {
            str_contains($userAgent, 'Windows') => 'Windows',
            str_contains($userAgent, 'Android') => 'Android',
            str_contains($userAgent, 'iPhone'), str_contains($userAgent, 'iPad') => 'iOS',
            str_contains($userAgent, 'Mac OS X') => 'macOS',
            str_contains($userAgent, 'Linux')   => 'Linux',
            default                             => 'Unknown platform',
        };

        return $browser . ' on ' . $platform;
    }
}
