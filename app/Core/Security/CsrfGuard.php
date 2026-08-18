<?php

declare(strict_types=1);

namespace App\Core\Security;

use App\Core\Session;
use App\Core\Support\Str;

/**
 * Cross-Site Request Forgery protection.
 *
 * A pool of tokens is held in the session rather than a single value, so that
 * a user working across several browser tabs does not invalidate one tab's
 * form by loading a page in another. Tokens expire, and the pool is bounded so
 * it cannot grow without limit.
 *
 * @package App\Core\Security
 * @version 1.0.0
 */
class CsrfGuard
{
    private const SESSION_KEY = '_csrf_tokens';

    public function __construct(private readonly Session $session)
    {
    }

    /**
     * Issue a token for the current session, reusing a fresh one when
     * available so that a page render does not always mint a new value.
     */
    public function token(): string
    {
        $tokens = $this->tokens();

        // Reuse the newest token while it still has most of its life left.
        $newest = end($tokens);
        if ($newest !== false && (time() - (int) $newest) < 60) {
            $keys = array_keys($tokens);

            return (string) end($keys);
        }

        return $this->generate();
    }

    /**
     * Mint a new token and add it to the pool.
     */
    public function generate(): string
    {
        $tokens = $this->prune($this->tokens());
        $token  = Str::randomToken(32);

        $tokens[$token] = time();

        // Bound the pool: the oldest entries fall off the front.
        $maximum = max(1, (int) config('security.csrf.pool_size', 12));
        if (count($tokens) > $maximum) {
            $tokens = array_slice($tokens, -$maximum, null, true);
        }

        $this->session->put(self::SESSION_KEY, $tokens);

        return $token;
    }

    /**
     * Verify a submitted token in constant time and consume nothing.
     *
     * Tokens are not single-use: an AJAX-heavy dashboard would otherwise
     * invalidate its own token on the first request. Expiry plus session
     * binding provides the protection.
     */
    public function verify(?string $candidate): bool
    {
        if ($candidate === null || $candidate === '') {
            return false;
        }

        $tokens = $this->prune($this->tokens());

        foreach (array_keys($tokens) as $token) {
            if (Str::secureEquals((string) $token, $candidate)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Discard every issued token. Called on logout and on privilege change.
     */
    public function flush(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    /**
     * @return array<string,int> token => issued-at timestamp
     */
    private function tokens(): array
    {
        /** @var array<string,int> $tokens */
        $tokens = $this->session->get(self::SESSION_KEY, []);

        return is_array($tokens) ? $tokens : [];
    }

    /**
     * Drop tokens that have outlived the configured lifetime.
     *
     * @param array<string,int> $tokens
     *
     * @return array<string,int>
     */
    private function prune(array $tokens): array
    {
        $lifetime = (int) config('security.csrf.lifetime', 7200);

        if ($lifetime <= 0) {
            return $tokens;
        }

        $cutoff = time() - $lifetime;

        return array_filter($tokens, static fn (int $issuedAt): bool => $issuedAt >= $cutoff);
    }
}
