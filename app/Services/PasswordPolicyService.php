<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Security\Hasher;
use App\Core\Support\Str;
use App\Exceptions\ValidationException;
use App\Repositories\PasswordHistoryRepository;

/**
 * Enforces the configured password policy.
 *
 * Every path that sets a password — self-service change, administrative reset,
 * first-run seeding — goes through validate() here, so there is no way to
 * install a password that the policy would refuse.
 *
 * @package App\Services
 * @version 1.0.0
 */
class PasswordPolicyService
{
    /** @var list<string>|null Lazily loaded weak-password dictionary. */
    private ?array $dictionary = null;

    public function __construct(
        private readonly PasswordHistoryRepository $history,
        private readonly SettingsService $settings
    ) {
    }

    /**
     * Validate a candidate password against every rule.
     *
     * @param string      $password The candidate.
     * @param string|null $username Rejected when the password resembles it.
     * @param int|null    $userId   Checked against the reuse history when given.
     *
     * @return list<string> Human-readable failures; empty means acceptable.
     */
    public function check(string $password, ?string $username = null, ?int $userId = null): array
    {
        $failures = [];

        $minimum = $this->settings->getInt('security.password_min_length', (int) config('security.password.min_length', 12));
        $maximum = (int) config('security.password.max_length', 128);

        // A password consisting only of whitespace is rejected outright rather
        // than being trimmed into something shorter than the user intended.
        if (trim($password) === '') {
            return ['The password may not be blank or consist only of spaces.'];
        }

        if (mb_strlen($password) < $minimum) {
            $failures[] = sprintf('The password must be at least %d characters long.', $minimum);
        }

        if (mb_strlen($password) > $maximum) {
            $failures[] = sprintf('The password may not exceed %d characters.', $maximum);
        }

        if ((bool) config('security.password.require_uppercase', true) && preg_match('/[A-Z]/', $password) !== 1) {
            $failures[] = 'The password must contain at least one upper-case letter.';
        }

        if ((bool) config('security.password.require_lowercase', true) && preg_match('/[a-z]/', $password) !== 1) {
            $failures[] = 'The password must contain at least one lower-case letter.';
        }

        if ((bool) config('security.password.require_numeric', true) && preg_match('/[0-9]/', $password) !== 1) {
            $failures[] = 'The password must contain at least one number.';
        }

        if ((bool) config('security.password.require_special', true)
            && preg_match('/[^A-Za-z0-9]/', $password) !== 1) {
            $failures[] = 'The password must contain at least one special character.';
        }

        if ($username !== null && $username !== '' && (bool) config('security.password.reject_similar_to_username', true)) {
            $threshold = (float) config('security.password.similarity_threshold', 0.7);

            if (stripos($password, $username) !== false
                || Str::similarity($password, $username) >= $threshold) {
                $failures[] = 'The password is too similar to the username.';
            }
        }

        if ($this->isCommon($password)) {
            $failures[] = 'The password appears in the list of commonly used passwords.';
        }

        if ($userId !== null) {
            $depth = $this->settings->getInt('security.password_history', (int) config('security.password.history_depth', 5));

            if ($this->history->matchesRecent($userId, $password, $depth)) {
                $failures[] = sprintf('The password matches one of your last %d passwords.', $depth);
            }
        }

        return $failures;
    }

    /**
     * Validate and throw on failure.
     *
     * @throws ValidationException
     */
    public function validate(string $password, ?string $username = null, ?int $userId = null, string $field = 'password'): void
    {
        $failures = $this->check($password, $username, $userId);

        if ($failures !== []) {
            throw new ValidationException([$field => $failures]);
        }
    }

    /**
     * Hash a password and record the previous one in the reuse history.
     */
    public function hashAndRecord(int $userId, string $password, ?int $changedBy): string
    {
        $hash  = Hasher::make($password);
        $depth = $this->settings->getInt('security.password_history', (int) config('security.password.history_depth', 5));

        if ($depth > 0) {
            $this->history->record($userId, $hash, $changedBy, $depth);
        }

        return $hash;
    }

    /**
     * Whether a password has passed the configured maximum age.
     */
    public function isExpired(?string $passwordChangedAt): bool
    {
        $maxAgeDays = $this->settings->getInt(
            'security.password_max_age_days',
            (int) config('security.password.max_age_days', 90)
        );

        if ($maxAgeDays <= 0) {
            return false;
        }

        // A password with no recorded change date is treated as expired: it
        // predates the policy and should be replaced.
        if ($passwordChangedAt === null || $passwordChangedAt === '') {
            return true;
        }

        $changedAt = strtotime($passwordChangedAt);

        if ($changedAt === false) {
            return true;
        }

        return $changedAt < strtotime('-' . $maxAgeDays . ' days');
    }

    /**
     * Days remaining before a password expires; null when expiry is disabled.
     */
    public function daysUntilExpiry(?string $passwordChangedAt): ?int
    {
        $maxAgeDays = $this->settings->getInt(
            'security.password_max_age_days',
            (int) config('security.password.max_age_days', 90)
        );

        if ($maxAgeDays <= 0) {
            return null;
        }

        $changedAt = $passwordChangedAt === null ? false : strtotime($passwordChangedAt);

        if ($changedAt === false) {
            return 0;
        }

        $expiresAt = $changedAt + ($maxAgeDays * 86400);

        return (int) max(0, ceil(($expiresAt - time()) / 86400));
    }

    /**
     * Generate a policy-compliant password for an administrative reset.
     */
    public function generate(): string
    {
        $minimum = $this->settings->getInt('security.password_min_length', 12);
        $length  = max(16, $minimum + 4);

        $upper   = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lower   = 'abcdefghijkmnopqrstuvwxyz';
        $digits  = '23456789';
        $symbols = '!@#$%^&*-_=+?';
        $all     = $upper . $lower . $digits . $symbols;

        // Seed one character from each required class, so the generated value
        // can never fail the policy it was generated to satisfy.
        $characters = [
            $upper[random_int(0, strlen($upper) - 1)],
            $lower[random_int(0, strlen($lower) - 1)],
            $digits[random_int(0, strlen($digits) - 1)],
            $symbols[random_int(0, strlen($symbols) - 1)],
        ];

        for ($i = count($characters); $i < $length; $i++) {
            $characters[] = $all[random_int(0, strlen($all) - 1)];
        }

        for ($i = count($characters) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$characters[$i], $characters[$j]] = [$characters[$j], $characters[$i]];
        }

        return implode('', $characters);
    }

    /**
     * A coarse strength score from 0 to 100, shown as a meter beside the field.
     */
    public function strength(string $password): int
    {
        if ($password === '') {
            return 0;
        }

        $score = 0;

        // Length contributes the most, because it genuinely matters most.
        $score += min(40, mb_strlen($password) * 3);

        $score += preg_match('/[a-z]/', $password) === 1 ? 10 : 0;
        $score += preg_match('/[A-Z]/', $password) === 1 ? 10 : 0;
        $score += preg_match('/[0-9]/', $password) === 1 ? 10 : 0;
        $score += preg_match('/[^A-Za-z0-9]/', $password) === 1 ? 15 : 0;

        // Variety of distinct characters guards against "aaaaaaaaaaaa".
        $distinct = count(array_unique(mb_str_split($password)));
        $score += min(15, $distinct);

        if ($this->isCommon($password)) {
            $score = min($score, 20);
        }

        if (preg_match('/^(.)\1+$/', $password) === 1) {
            $score = min($score, 10);
        }

        return max(0, min(100, $score));
    }

    /**
     * Describe a score for display.
     */
    public function strengthLabel(int $score): string
    {
        return match (true) {
            $score >= 80 => 'Strong',
            $score >= 60 => 'Good',
            $score >= 40 => 'Fair',
            $score >= 20 => 'Weak',
            default      => 'Very weak',
        };
    }

    /**
     * Whether a password appears in the configured weak-password dictionary.
     */
    private function isCommon(string $password): bool
    {
        return in_array(mb_strtolower($password), $this->loadDictionary(), true);
    }

    /**
     * @return list<string>
     */
    private function loadDictionary(): array
    {
        if ($this->dictionary !== null) {
            return $this->dictionary;
        }

        $path = base_path((string) config('security.password.dictionary_file', 'config/weak-passwords.txt'));

        if (!is_readable($path)) {
            return $this->dictionary = [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return $this->dictionary = [];
        }

        return $this->dictionary = array_values(array_filter(array_map(
            static fn (string $line): string => mb_strtolower(trim($line)),
            $lines
        ), static fn (string $line): bool => $line !== '' && !str_starts_with($line, '#')));
    }
}
