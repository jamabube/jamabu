<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database\Connection;
use App\Core\Database\Paginator;
use App\Core\Security\AuthGuard;
use App\Core\Security\Hasher;
use App\Exceptions\AuthorizationException;
use App\Exceptions\BusinessRuleException;
use App\Exceptions\ConflictException;
use App\Exceptions\NotFoundException;
use App\Exceptions\ValidationException;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\RoleRepository;
use App\Repositories\UserRepository;
use App\Repositories\UserSessionRepository;

/**
 * User account administration.
 *
 * Two rules here exist to stop the system becoming unrecoverable or being used
 * to escalate privilege:
 *
 *   1. The last active administrator cannot be removed, deactivated, locked or
 *      demoted. An organisation locked out of its own access-control system is
 *      a worse outcome than any of the things that rule prevents.
 *   2. Nobody may create or promote a user into a role with more authority than
 *      their own, which closes the obvious escalation path.
 *
 * @package App\Services
 * @version 1.0.0
 */
class UserService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly RoleRepository $roles,
        private readonly UserSessionRepository $sessions,
        private readonly LoginAttemptRepository $attempts,
        private readonly PasswordPolicyService $passwordPolicy,
        private readonly NotificationService $notifications,
        private readonly AuditService $audit,
        private readonly AuthGuard $auth,
        private readonly Connection $connection
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @param array<string,mixed> $options
     */
    public function paginate(array $filters, array $options): Paginator
    {
        return $this->users->paginate($filters, $options);
    }

    /**
     * Everything the profile page shows.
     *
     * @return array<string,mixed>
     */
    public function profile(int $userId): array
    {
        $user = $this->users->findFromDirectory($userId);

        if ($user === null) {
            throw NotFoundException::record('User', $userId);
        }

        return [
            'user'            => $user,
            'permissions'     => $this->users->permissionsFor($userId),
            'sessions'        => $this->sessions->activeFor($userId),
            'login_history'   => $this->sessions->historyFor($userId, 10),
            'attempts'        => $this->attempts->forUser($userId, 10),
            'password_expiry' => $this->passwordPolicy->daysUntilExpiry(
                $user['password_changed_at'] === null ? null : (string) $user['password_changed_at']
            ),
        ];
    }

    /**
     * Create an account and return its id plus the temporary password.
     *
     * @param array<string,mixed> $attributes
     *
     * @return array{user_id:int,password:string}
     */
    public function create(array $attributes, ?string $password = null): array
    {
        $username = strtolower(trim((string) ($attributes['username'] ?? '')));
        $email    = strtolower(trim((string) ($attributes['email'] ?? '')));
        $roleId   = (int) ($attributes['role_id'] ?? 0);

        $this->assertUsernameAvailable($username, null);
        $this->assertEmailAvailable($email, null);
        $this->assertRoleAssignable($roleId);

        // A generated password is used unless the administrator supplied one,
        // and either way the account must change it at first sign-in.
        $password ??= $this->passwordPolicy->generate();

        $this->passwordPolicy->validate($password, $username, null);

        $attributes['employee_number'] = trim((string) ($attributes['employee_number'] ?? ''));
        if ($attributes['employee_number'] === '') {
            $attributes['employee_number'] = $this->users->nextEmployeeNumber();
        }

        $userId = $this->connection->transaction(function () use ($attributes, $username, $email, $password): int {
            $id = $this->users->create(array_merge($attributes, [
                'username'             => $username,
                'email'                => $email,
                'password_hash'        => Hasher::make($password),
                'password_changed_at'  => now()->format('Y-m-d H:i:s'),
                'must_change_password' => 1,
                'created_by'           => $this->auth->id(),
                'updated_by'           => $this->auth->id(),
            ]));

            return $id;
        });

        $this->audit->created('users', 'users', $userId, sprintf(
            'User account "%s" was created.',
            $username
        ), ['username' => $username, 'email' => $email, 'role_id' => $roleId]);

        $this->notifications->raise('user.created', [
            'title'        => 'New user account',
            'description'  => sprintf('Account "%s" was created by %s.', $username, $this->auth->displayName()),
            'link'         => '/users/' . $userId,
            'related_type' => 'users',
            'related_id'   => $userId,
        ]);

        return ['user_id' => $userId, 'password' => $password];
    }

    /**
     * Update an account.
     *
     * @param array<string,mixed> $attributes
     */
    public function update(int $userId, array $attributes): void
    {
        $existing = $this->users->findWithRole($userId);

        if ($existing === null) {
            throw NotFoundException::record('User', $userId);
        }

        if (isset($attributes['username'])) {
            $attributes['username'] = strtolower(trim((string) $attributes['username']));
            $this->assertUsernameAvailable($attributes['username'], $userId);
        }

        if (isset($attributes['email'])) {
            $attributes['email'] = strtolower(trim((string) $attributes['email']));
            $this->assertEmailAvailable($attributes['email'], $userId);
        }

        if (isset($attributes['role_id']) && (int) $attributes['role_id'] !== (int) $existing['role_id']) {
            $this->assertRoleAssignable((int) $attributes['role_id']);
            $this->assertNotLastAdministrator($userId, 'change the role of');
        }

        // A password is never set through the general update path; that goes
        // through resetPassword or changePassword so the policy always applies.
        unset($attributes['password'], $attributes['password_hash'], $attributes['must_change_password']);

        $this->users->update($userId, array_merge($attributes, ['updated_by' => $this->auth->id()]));

        $this->audit->updated('users', 'users', $userId, sprintf(
            'User account "%s" was updated.',
            (string) $existing['username']
        ), $existing, $attributes);

        // A role change alters what the account may do, so its sessions are
        // ended rather than left running with a stale permission cache.
        if (isset($attributes['role_id']) && (int) $attributes['role_id'] !== (int) $existing['role_id']) {
            $this->sessions->closeAllFor($userId, 'administrator', null, $this->auth->id());
        }
    }

    /**
     * Issue a new password for another user.
     *
     * @return string The temporary password, shown once.
     */
    public function resetPassword(int $userId, ?string $password = null): string
    {
        $user = $this->users->findWithRole($userId);

        if ($user === null) {
            throw NotFoundException::record('User', $userId);
        }

        $password ??= $this->passwordPolicy->generate();

        $this->passwordPolicy->validate($password, (string) $user['username'], $userId);

        $hash = $this->passwordPolicy->hashAndRecord($userId, $password, $this->auth->id());

        $this->connection->transaction(function () use ($userId, $hash): void {
            $this->users->updatePassword($userId, $hash, true);
            $this->users->unlock($userId);

            // Every existing session is ended: after a reset, only somebody who
            // knows the new password should hold access.
            $this->sessions->closeAllFor($userId, 'password_change', null, $this->auth->id());
        });

        $this->audit->record('users', 'password_reset', sprintf(
            'The password for "%s" was reset by an administrator; all its sessions were ended.',
            (string) $user['username']
        ), ['record_type' => 'users', 'record_id' => $userId]);

        return $password;
    }

    /**
     * Change one's own password.
     *
     * @throws ValidationException
     */
    public function changeOwnPassword(int $userId, string $currentPassword, string $newPassword): void
    {
        $user = $this->users->findWithRole($userId);

        if ($user === null) {
            throw NotFoundException::record('User', $userId);
        }

        if (!Hasher::verify($currentPassword, (string) $user['password_hash'])) {
            throw new ValidationException(['current_password' => ['The current password is incorrect.']]);
        }

        $this->passwordPolicy->validate($newPassword, (string) $user['username'], $userId, 'new_password');

        $hash = $this->passwordPolicy->hashAndRecord($userId, $newPassword, $userId);

        $this->connection->transaction(function () use ($userId, $hash): void {
            $this->users->updatePassword($userId, $hash, false);

            // Other sessions are ended, but the one performing the change is
            // kept: signing the user out of the page they are on would be
            // hostile, and they have just proved they know the credential.
            $this->sessions->closeAllFor(
                $userId,
                'password_change',
                $this->auth->session()->id(),
                $userId
            );
        });

        $this->audit->record('users', 'password_changed', sprintf(
            '"%s" changed their own password.',
            (string) $user['username']
        ), ['record_type' => 'users', 'record_id' => $userId]);

        $this->notifications->raise('user.password_changed', [
            'title'       => 'Password changed',
            'description' => sprintf('The password for "%s" was changed.', (string) $user['username']),
            'link'        => '/users/' . $userId,
        ]);
    }

    public function lock(int $userId, string $reason): void
    {
        $user = $this->users->findOrFail($userId);

        $this->assertNotLastAdministrator($userId, 'lock');
        $this->assertNotSelf($userId, 'lock your own account');

        $this->connection->transaction(function () use ($userId, $reason): void {
            $this->users->lock($userId, null, $reason);
            $this->sessions->closeAllFor($userId, 'administrator', null, $this->auth->id());
        });

        $this->audit->record('users', 'locked', sprintf(
            'Account "%s" was locked: %s',
            (string) $user['username'],
            $reason
        ), ['record_type' => 'users', 'record_id' => $userId]);
    }

    public function unlock(int $userId): void
    {
        $user = $this->users->findOrFail($userId);

        $this->users->unlock($userId);

        $this->audit->record('users', 'unlocked', sprintf(
            'Account "%s" was unlocked.',
            (string) $user['username']
        ), ['record_type' => 'users', 'record_id' => $userId]);
    }

    public function deactivate(int $userId): void
    {
        $user = $this->users->findOrFail($userId);

        $this->assertNotLastAdministrator($userId, 'deactivate');
        $this->assertNotSelf($userId, 'deactivate your own account');

        $this->connection->transaction(function () use ($userId): void {
            $this->users->update($userId, ['status' => 'inactive', 'updated_by' => $this->auth->id()]);
            $this->users->delete($userId, $this->auth->id());
            $this->sessions->closeAllFor($userId, 'administrator', null, $this->auth->id());
        });

        // The account is soft-deleted so the audit records naming it still
        // resolve to a person.
        $this->audit->deleted('users', 'users', $userId, sprintf(
            'Account "%s" was deactivated.',
            (string) $user['username']
        ), ['username' => $user['username'], 'status' => $user['status']]);
    }

    public function restore(int $userId): void
    {
        $this->users->restore($userId);
        $this->users->update($userId, ['status' => 'active', 'updated_by' => $this->auth->id()]);

        $this->audit->record('users', 'restored', sprintf('Account %d was restored.', $userId), [
            'record_type' => 'users',
            'record_id'   => $userId,
        ]);
    }

    /**
     * End one session belonging to a user.
     */
    public function terminateSession(int $sessionRowId, int $userId): void
    {
        $this->sessions->closeById($sessionRowId, 'administrator', $this->auth->id());

        $this->audit->record('users', 'session_terminated', sprintf(
            'An active session for user %d was terminated by an administrator.',
            $userId
        ), ['record_type' => 'user_sessions', 'record_id' => $sessionRowId]);
    }

    // ------------------------------------------------------------------
    // Guard rails
    // ------------------------------------------------------------------

    /**
     * @throws ConflictException
     */
    private function assertUsernameAvailable(string $username, ?int $exceptId): void
    {
        if ($username === '') {
            throw new ValidationException(['username' => ['A username is required.']]);
        }

        if ($this->users->existsWhere('username', $username, $exceptId)) {
            throw ConflictException::duplicate('user', 'username', $username);
        }
    }

    /**
     * @throws ConflictException
     */
    private function assertEmailAvailable(string $email, ?int $exceptId): void
    {
        if ($email !== '' && $this->users->existsWhere('email', $email, $exceptId)) {
            throw ConflictException::duplicate('user', 'email address', $email);
        }
    }

    /**
     * Nobody may grant a role with more authority than their own.
     *
     * @throws AuthorizationException
     */
    private function assertRoleAssignable(int $roleId): void
    {
        $role = $this->roles->find($roleId);

        if ($role === null) {
            throw NotFoundException::record('Role', $roleId);
        }

        // An administrator holds the wildcard and may assign anything.
        if ($this->auth->can('*')) {
            return;
        }

        $actor = $this->auth->user();

        if ($actor === null) {
            throw new AuthorizationException('You may not assign roles.');
        }

        $actorRole = $this->roles->find($actor->roleId);
        $actorPriority = $actorRole === null ? PHP_INT_MAX : (int) $actorRole['priority'];

        if ((int) $role['priority'] < $actorPriority) {
            throw new AuthorizationException(sprintf(
                'You may not assign the "%s" role, which carries more authority than your own.',
                (string) $role['role_name']
            ));
        }
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertNotLastAdministrator(int $userId, string $action): void
    {
        if (!$this->users->isAdministrator($userId)) {
            return;
        }

        if ($this->users->countActiveAdministrators() > 1) {
            return;
        }

        throw BusinessRuleException::withCode(
            'LAST_ADMINISTRATOR',
            sprintf(
                'This is the only active administrator account. You cannot %s it, because doing so would leave the system with no way to administer it.',
                $action
            )
        );
    }

    /**
     * @throws BusinessRuleException
     */
    private function assertNotSelf(int $userId, string $action): void
    {
        if ($this->auth->id() === $userId) {
            throw BusinessRuleException::withCode(
                'SELF_ACTION_REFUSED',
                sprintf('You cannot %s.', $action)
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function summary(): array
    {
        return [
            'statuses'        => $this->users->statusCounts(),
            'active_sessions' => $this->sessions->countActive(),
            'administrators'  => $this->users->countActiveAdministrators(),
        ];
    }
}
