<?php

declare(strict_types=1);

namespace App\Core\Security;

/**
 * Password hashing.
 *
 * bcrypt with a configurable cost. There is deliberately no method that
 * reverses a hash: a password is verified, never decrypted.
 *
 * @package App\Core\Security
 * @version 1.0.0
 */
final class Hasher
{
    /**
     * Hash a plain-text password.
     */
    public static function make(string $plain): string
    {
        $hash = password_hash($plain, PASSWORD_BCRYPT, ['cost' => self::cost()]);

        // password_hash only returns false on a misconfigured algorithm, which
        // would be a deployment fault worth failing loudly on.
        if ($hash === false || $hash === '') {
            throw new \RuntimeException('The password could not be hashed; check the PHP bcrypt configuration.');
        }

        return $hash;
    }

    /**
     * Verify a plain-text password against a stored hash.
     *
     * password_verify is constant-time with respect to the hash contents, so
     * this comparison does not leak information through timing.
     */
    public static function verify(string $plain, string $hash): bool
    {
        if ($hash === '') {
            // Still burn the time a real verification would take, so that a
            // request for a non-existent account is not measurably faster.
            self::burn();

            return false;
        }

        return password_verify($plain, $hash);
    }

    /**
     * Whether a stored hash was produced with weaker parameters than the
     * current policy and should be upgraded on the next successful sign-in.
     */
    public static function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_BCRYPT, ['cost' => self::cost()]);
    }

    /**
     * Perform a dummy verification so that an authentication attempt against
     * an unknown username costs the same as one against a real account.
     * Without this, response timing discloses which usernames exist.
     *
     * The reference hash is generated at the configured cost rather than
     * hardcoded, so the decoy always takes as long as the real thing even
     * after the cost factor is raised.
     */
    public static function burn(): void
    {
        password_verify('vams-timing-equaliser', self::referenceHash());
    }

    /**
     * A hash produced at the current cost, built once per process.
     */
    private static function referenceHash(): string
    {
        /** @var array<int,string> $cache Keyed by cost, so a config change is honoured. */
        static $cache = [];

        $cost = self::cost();

        return $cache[$cost] ??= (string) password_hash(
            bin2hex(random_bytes(16)),
            PASSWORD_BCRYPT,
            ['cost' => $cost]
        );
    }

    /**
     * The configured bcrypt cost, clamped to a sane range.
     */
    private static function cost(): int
    {
        $cost = (int) config('security.password.bcrypt_cost', 12);

        return max(10, min(15, $cost));
    }

    /**
     * Hash a token (password-reset link, API key, session identifier) for
     * storage. SHA-256 is correct here rather than bcrypt: these values are
     * already high-entropy random strings, so key stretching adds cost without
     * adding security, and lookups must stay indexable.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
