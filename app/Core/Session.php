<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Support\Str;

/**
 * Secure session wrapper.
 *
 * Owns cookie hardening, identifier regeneration, idle and absolute lifetime
 * enforcement, and the fingerprint check that defeats a stolen session cookie
 * replayed from a different browser.
 *
 * @package App\Core
 * @version 1.0.0
 */
class Session
{
    private bool $started = false;

    /** @var array<string,mixed> Flash data carried over from the previous request. */
    private array $previousFlash = [];

    /**
     * Configure and start the session.
     *
     * @param array<string,mixed> $config Contents of config/session.php.
     */
    public function start(array $config, bool $secureRequest): void
    {
        if ($this->started || PHP_SAPI === 'cli') {
            $this->started = true;

            return;
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            $this->rotateFlash();

            return;
        }

        /** @var array<string,mixed> $cookie */
        $cookie = $config['cookie'] ?? [];

        // The Secure flag is only meaningful over TLS; setting it on a plain
        // HTTP request would make the cookie unusable and silently break login
        // during a first-run HTTP installation.
        $secureFlag = ((bool) ($cookie['secure'] ?? true)) && $secureRequest;

        session_name((string) ($config['name'] ?? 'VAMSSESSID'));

        $savePath = (string) ($config['save_path'] ?? '');
        if ($savePath !== '' && is_dir($savePath)) {
            session_save_path($savePath);
        }

        session_set_cookie_params([
            'lifetime' => 0, // browser-session cookie; server side controls expiry
            'path'     => (string) ($cookie['path'] ?? '/'),
            'domain'   => (string) ($cookie['domain'] ?? ''),
            'secure'   => $secureFlag,
            'httponly' => (bool) ($cookie['http_only'] ?? true),
            'samesite' => (string) ($cookie['same_site'] ?? 'Lax'),
        ]);

        // The identifier must never travel in a URL.
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.cache_limiter', 'nocache');

        /*
         * PHP 8.4 deprecated these two: the engine now always generates a
         * 32-character identifier over a 4-bit alphabet, which is the entropy
         * these settings were asking for in the first place. Setting them on
         * 8.4 raises a deprecation notice, and this application promotes
         * notices to exceptions — so on 8.4 and later the defaults are simply
         * left alone.
         */
        if (PHP_VERSION_ID < 80400) {
            ini_set('session.sid_length', '48');
            ini_set('session.sid_bits_per_character', '5');
        }

        session_start();

        $this->started = true;
        $this->rotateFlash();
    }

    /**
     * Move flash data written during the previous request into a read buffer
     * and clear it, so each flash value survives exactly one redirect.
     */
    private function rotateFlash(): void
    {
        /** @var array<string,mixed> $flash */
        $flash = $_SESSION['_flash'] ?? [];
        $this->previousFlash = $flash;
        $_SESSION['_flash']  = [];
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * @return array<string,mixed>
     */
    public function all(): array
    {
        /** @var array<string,mixed> $data */
        $data = $_SESSION ?? [];

        return $data;
    }

    /**
     * Store a value readable on the next request only.
     */
    public function flash(string $key, mixed $value): void
    {
        $_SESSION['_flash'][$key] = $value;
    }

    /**
     * Read a value flashed by the previous request.
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $this->previousFlash[$key] ?? $default;
    }

    public function hasFlash(string $key): bool
    {
        return array_key_exists($key, $this->previousFlash);
    }

    /**
     * @return array<string,mixed>
     */
    public function allFlash(): array
    {
        return $this->previousFlash;
    }

    /**
     * Re-flash a value so it survives one more request. Used when a request
     * that would have consumed the flash ends in a redirect instead.
     */
    public function reflash(string $key): void
    {
        if (array_key_exists($key, $this->previousFlash)) {
            $this->flash($key, $this->previousFlash[$key]);
        }
    }

    public function id(): string
    {
        return session_id() ?: '';
    }

    /**
     * Regenerate the session identifier, preserving the session contents.
     *
     * Called immediately after a successful authentication so that a fixation
     * attempt using a pre-set identifier cannot survive login.
     */
    public function regenerate(bool $deleteOldSession = true): void
    {
        if (PHP_SAPI === 'cli' || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        session_regenerate_id($deleteOldSession);
        $this->put('_regenerated_at', time());
    }

    /**
     * Regenerate periodically to shorten the useful life of a leaked id.
     */
    public function regenerateIfDue(int $intervalSeconds): void
    {
        if ($intervalSeconds <= 0) {
            return;
        }

        $last = (int) $this->get('_regenerated_at', 0);

        if ($last === 0) {
            $this->put('_regenerated_at', time());

            return;
        }

        if (time() - $last >= $intervalSeconds) {
            $this->regenerate();
        }
    }

    /**
     * Destroy the session entirely and expire its cookie.
     */
    public function destroy(): void
    {
        if (PHP_SAPI === 'cli') {
            $_SESSION = [];

            return;
        }

        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name() ?: 'VAMSSESSID', '', [
                'expires'  => time() - 42000,
                'path'     => $params['path'],
                'domain'   => $params['domain'],
                'secure'   => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'],
            ]);
        }

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        $this->started = false;
    }

    /**
     * Compute the fingerprint that binds a session to its originating client.
     */
    public function fingerprint(string $userAgent, string $ipAddress, bool $bindIp): string
    {
        $material = $userAgent . '|' . ($bindIp ? $ipAddress : '') . '|' . (string) config('app.key', '');

        return hash('sha256', $material);
    }

    /**
     * Record the fingerprint for the current session.
     */
    public function bindFingerprint(string $fingerprint): void
    {
        $this->put('_fingerprint', $fingerprint);
    }

    /**
     * Whether the stored fingerprint still matches the current client.
     */
    public function fingerprintMatches(string $fingerprint): bool
    {
        $stored = (string) $this->get('_fingerprint', '');

        // A session that predates fingerprinting is accepted once and then bound.
        if ($stored === '') {
            $this->bindFingerprint($fingerprint);

            return true;
        }

        return Str::secureEquals($stored, $fingerprint);
    }

    /**
     * Refresh the activity marker used for idle-timeout enforcement.
     */
    public function touch(): void
    {
        $this->put('_last_activity', time());
    }

    public function lastActivity(): int
    {
        return (int) $this->get('_last_activity', time());
    }

    public function startedAt(): int
    {
        $startedAt = (int) $this->get('_started_at', 0);

        if ($startedAt === 0) {
            $startedAt = time();
            $this->put('_started_at', $startedAt);
        }

        return $startedAt;
    }

    /**
     * Whether the session exceeded its idle window.
     */
    public function isIdleExpired(int $lifetimeSeconds): bool
    {
        if ($lifetimeSeconds <= 0) {
            return false;
        }

        return (time() - $this->lastActivity()) > $lifetimeSeconds;
    }

    /**
     * Whether the session exceeded its absolute maximum age, regardless of
     * activity. Bounds the damage from a session that is kept alive by a
     * background tab.
     */
    public function isAbsoluteExpired(int $maximumSeconds): bool
    {
        if ($maximumSeconds <= 0) {
            return false;
        }

        return (time() - $this->startedAt()) > $maximumSeconds;
    }

    /**
     * Seconds of inactivity remaining before automatic logout.
     */
    public function secondsUntilIdleTimeout(int $lifetimeSeconds): int
    {
        if ($lifetimeSeconds <= 0) {
            return PHP_INT_MAX;
        }

        return max(0, $lifetimeSeconds - (time() - $this->lastActivity()));
    }
}
