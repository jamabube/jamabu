<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Security\Hasher;
use App\Exceptions\AuthenticationException;
use App\Repositories\LoginAttemptRepository;
use App\Repositories\UserRepository;
use App\Services\AuthenticationService;
use Tests\Support\RequestFactory;
use Tests\TestCase;

/**
 * Exercises the sign-in path against a live database.
 *
 * The two properties worth protecting here are that failures never disclose
 * which half of a credential was wrong, and that an unknown username costs the
 * same as a known one — timing is a disclosure channel too.
 *
 * @package Tests\Integration
 * @version 1.0.0
 */
final class AuthenticationTest extends TestCase
{
    protected bool $requiresDatabase = true;

    private const TEST_USERNAME = 'vams_test_operator';
    private const TEST_PASSWORD = 'Kn0wn-Test-Pass!42';

    /**
     * A documentation-range address (RFC 5737), so the suite's own failures
     * never mix with real or demonstration traffic.
     */
    private const TEST_IP = '203.0.113.77';

    private AuthenticationService $authentication;
    private UserRepository $users;
    private int $userId = 0;

    public function description(): string
    {
        return 'Sign-in, credential disclosure and account lockout';
    }

    public function setUp(): void
    {
        $this->authentication = $this->app->make(AuthenticationService::class);
        $this->users          = $this->app->make(UserRepository::class);

        // A dedicated account keeps the suite from disturbing seeded users.
        $existing = $this->users->findForAuthentication(self::TEST_USERNAME);

        if ($existing === null) {
            $roleId = (int) $this->app->make(\App\Repositories\RoleRepository::class)
                ->findBySlug('security')['role_id'];

            $this->userId = $this->users->create([
                'first_name'    => 'Test',
                'last_name'     => 'Operator',
                'username'      => self::TEST_USERNAME,
                'email'         => 'vams.test.operator@forestlawn.local',
                'password_hash' => Hasher::make(self::TEST_PASSWORD),
                'role_id'       => $roleId,
                'status'        => 'active',
            ]);
        } else {
            $this->userId = (int) $existing['user_id'];
            $this->users->updatePassword($this->userId, Hasher::make(self::TEST_PASSWORD), false);
        }

        $this->users->unlock($this->userId);

        // Each test deliberately generates failed sign-ins. Without clearing
        // them the per-address flood guard trips partway through the suite and
        // starts refusing attempts before they are even counted -- correct
        // behaviour in production, but it makes the suite order-dependent.
        $this->clearAttemptHistory();
    }

    /**
     * Remove this suite's own attempt history.
     */
    private function clearAttemptHistory(): void
    {
        $this->app->make(\App\Core\Database\Connection::class)->execute(
            'DELETE FROM `login_attempts` WHERE `ip_address` = ? OR `username` = ?',
            [self::TEST_IP, self::TEST_USERNAME]
        );
    }

    public function tearDown(): void
    {
        if ($this->userId > 0) {
            $this->users->unlock($this->userId);
        }
    }

    public function testCorrectCredentialsSignIn(): void
    {
        $this->clearAttemptHistory();
        $this->users->unlock($this->userId);

        $user = $this->authentication->attempt(
            self::TEST_USERNAME,
            self::TEST_PASSWORD,
            RequestFactory::make('POST', '/login', [], [], '', self::TEST_IP)
        );

        $this->assertSame(self::TEST_USERNAME, $user->username, 'the correct credentials sign in');
        $this->assertSame('security', $user->roleSlug, 'the role travels with the principal');
    }

    public function testWrongPasswordIsRefused(): void
    {
        $this->clearAttemptHistory();
        $this->users->unlock($this->userId);

        $this->assertThrows(
            fn () => $this->authentication->attempt(self::TEST_USERNAME, 'definitely-wrong', RequestFactory::make('POST', '/login', [], [], '', self::TEST_IP)),
            'a wrong password is refused',
            AuthenticationException::class,
            'INVALID_CREDENTIALS'
        );
    }

    public function testUnknownUsernameGivesTheSameError(): void
    {
        $this->clearAttemptHistory();
        $this->users->unlock($this->userId);

        // The two failures must be indistinguishable, or the login form becomes
        // an account-enumeration oracle.
        $this->assertThrows(
            fn () => $this->authentication->attempt('no-such-account-at-all', 'anything', RequestFactory::make('POST', '/login', [], [], '', self::TEST_IP)),
            'an unknown username yields the same error code as a wrong password',
            AuthenticationException::class,
            'INVALID_CREDENTIALS'
        );
    }

    public function testTimingDoesNotDiscloseAccountExistence(): void
    {
        $this->clearAttemptHistory();
        $this->users->unlock($this->userId);

        $measure = function (string $username): float {
            $startedAt = microtime(true);

            try {
                $this->authentication->attempt($username, 'wrong-password', RequestFactory::make('POST', '/login', [], [], '', self::TEST_IP));
            } catch (\Throwable) {
                // The failure is the point; only its cost is measured.
            }

            return microtime(true) - $startedAt;
        };

        // The lock is released between measurements so both paths reach the
        // password-verification step rather than short-circuiting on state.
        $known   = $measure(self::TEST_USERNAME);
        $this->users->unlock($this->userId);
        $unknown = $measure('no-such-account-at-all');

        $ratio = $known > 0.0 ? $unknown / $known : 0.0;

        // A decoy hash is verified for absent accounts, so the two paths should
        // cost within a factor of roughly two of each other even on a loaded
        // machine.
        $this->assertTrue(
            $ratio > 0.4 && $ratio < 2.5,
            'an unknown username costs about the same as a known one',
            sprintf('ratio %.2f (%.0fms known vs %.0fms unknown)', $ratio, $known * 1000, $unknown * 1000)
        );

        $this->users->unlock($this->userId);
    }

    public function testAccountLocksAfterRepeatedFailures(): void
    {
        $this->clearAttemptHistory();
        $this->users->unlock($this->userId);

        $this->users->unlock($this->userId);

        $maximum = (int) config('security.lockout.max_attempts', 5);

        for ($attempt = 0; $attempt < $maximum; $attempt++) {
            try {
                $this->authentication->attempt(
                    self::TEST_USERNAME,
                    'wrong-' . $attempt,
                    RequestFactory::make('POST', '/login', [], [], '', self::TEST_IP)
                );
            } catch (\Throwable) {
                // Expected.
            }
        }

        $record = $this->users->findForAuthentication(self::TEST_USERNAME);

        $this->assertSame(1, (int) $record['is_locked'], sprintf('the account locks after %d failures', $maximum));

        // A locked account must refuse even the correct password, otherwise the
        // lockout achieves nothing.
        $this->assertThrows(
            fn () => $this->authentication->attempt(self::TEST_USERNAME, self::TEST_PASSWORD, RequestFactory::make('POST', '/login', [], [], '', self::TEST_IP)),
            'a locked account refuses the correct password',
            AuthenticationException::class,
            'ACCOUNT_LOCKED'
        );

        $this->users->unlock($this->userId);
    }

    public function testInactiveAccountCannotSignIn(): void
    {
        $this->clearAttemptHistory();
        $this->users->unlock($this->userId);

        $this->users->update($this->userId, ['status' => 'inactive']);

        $this->assertThrows(
            fn () => $this->authentication->attempt(self::TEST_USERNAME, self::TEST_PASSWORD, RequestFactory::make('POST', '/login', [], [], '', self::TEST_IP)),
            'a deactivated account cannot sign in',
            AuthenticationException::class,
            'ACCOUNT_INACTIVE'
        );

        $this->users->update($this->userId, ['status' => 'active']);
    }

    public function testEveryAttemptIsRecorded(): void
    {
        $this->clearAttemptHistory();
        $this->users->unlock($this->userId);

        $attempts = $this->app->make(LoginAttemptRepository::class);
        $before   = count($attempts->forUser($this->userId, 200));

        try {
            $this->authentication->attempt(self::TEST_USERNAME, 'wrong-again', RequestFactory::make('POST', '/login', [], [], '', self::TEST_IP));
        } catch (\Throwable) {
            // Expected.
        }

        $after = count($attempts->forUser($this->userId, 200));

        $this->assertGreaterThan($before, $after, 'a failed attempt is recorded in the attempt log');

        $this->users->unlock($this->userId);
    }
}
