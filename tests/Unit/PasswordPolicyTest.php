<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PasswordPolicyService;
use Tests\TestCase;

/**
 * Verifies that the password policy accepts what it should and refuses what it
 * should, including the cases most easily got wrong: whitespace-only input,
 * passwords resembling the username, and a generated password that fails the
 * very policy it was generated for.
 *
 * @package Tests\Unit
 * @version 1.0.0
 */
final class PasswordPolicyTest extends TestCase
{
    private PasswordPolicyService $policy;

    public function description(): string
    {
        return 'Password complexity, dictionary and generation rules';
    }

    public function setUp(): void
    {
        $this->policy = $this->app->make(PasswordPolicyService::class);
    }

    public function testRejectsTooShort(): void
    {
        $this->assertNotSame([], $this->policy->check('Ab1!'), 'a four-character password is refused');
    }

    public function testRejectsWhitespaceOnly(): void
    {
        $failures = $this->policy->check('                ');

        $this->assertNotSame([], $failures, 'a whitespace-only password is refused');
        $this->assertCount(1, $failures, 'the whitespace failure short-circuits the other checks');
    }

    public function testRequiresEachCharacterClass(): void
    {
        $this->assertContains(
            'The password must contain at least one upper-case letter.',
            $this->policy->check('lowercase123!'),
            'an all-lower-case password is refused'
        );

        $this->assertContains(
            'The password must contain at least one number.',
            $this->policy->check('NoDigitsHere!!'),
            'a password without a digit is refused'
        );

        $this->assertContains(
            'The password must contain at least one special character.',
            $this->policy->check('NoSymbolsHere1'),
            'a password without a symbol is refused'
        );
    }

    public function testRejectsDictionaryPasswords(): void
    {
        $this->assertNotSame([], $this->policy->check('Password123'), 'a dictionary password is refused');
        $this->assertNotSame([], $this->policy->check('Welcome123'), 'a second dictionary password is refused');
    }

    public function testRejectsPasswordResemblingUsername(): void
    {
        $this->assertNotSame(
            [],
            $this->policy->check('Administrator1!', 'administrator'),
            'a password containing the username is refused'
        );
    }

    public function testAcceptsAStrongPassword(): void
    {
        $failures = $this->policy->check('Tr0ub4dor&Kayak-93');

        $this->assertSame([], $failures, 'a long mixed-class password is accepted');
    }

    public function testGeneratedPasswordSatisfiesItsOwnPolicy(): void
    {
        // Ten rounds, because a generator that only usually complies is a
        // generator that will eventually produce an unusable reset.
        for ($attempt = 1; $attempt <= 10; $attempt++) {
            $generated = $this->policy->generate();
            $failures  = $this->policy->check($generated);

            if ($failures !== []) {
                $this->assertSame([], $failures, sprintf('generated password %d complies', $attempt));

                return;
            }
        }

        $this->assertTrue(true, 'ten generated passwords all comply with the policy');
    }

    public function testStrengthMeterRanksSensibly(): void
    {
        $strong = $this->policy->strength('Tr0ub4dor&Kayak-93');
        $weak   = $this->policy->strength('password');

        $this->assertGreaterThan($weak, $strong, 'a strong password scores above a weak one');
        $this->assertSame(0, $this->policy->strength(''), 'an empty password scores zero');
        $this->assertTrue($this->policy->strength('aaaaaaaaaaaaaaaa') <= 20, 'a repeated character scores poorly');
        $this->assertSame('Strong', $this->policy->strengthLabel(85), 'a high score is labelled Strong');
    }

    public function testPasswordExpiry(): void
    {
        $this->assertTrue(
            $this->policy->isExpired(null),
            'a password with no recorded change date is treated as expired'
        );

        $this->assertFalse(
            $this->policy->isExpired(now()->format('Y-m-d H:i:s')),
            'a password changed today is not expired'
        );

        $this->assertTrue(
            $this->policy->isExpired(now()->modify('-400 days')->format('Y-m-d H:i:s')),
            'a password older than the maximum age is expired'
        );
    }
}
