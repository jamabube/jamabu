<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Session;

/**
 * Redirect response with flash-message helpers.
 *
 * @package App\Core\Http
 * @version 1.0.0
 */
class RedirectResponse extends Response
{
    public function __construct(
        private readonly string $location,
        int $status = 302,
        private readonly ?Session $session = null
    ) {
        parent::__construct('', $status, [
            'Location'      => $this->sanitiseLocation($location),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function location(): string
    {
        return $this->location;
    }

    /**
     * Flash a success banner shown on the next page render.
     */
    public function withSuccess(string $message): static
    {
        return $this->flash('success', $message);
    }

    public function withError(string $message): static
    {
        return $this->flash('error', $message);
    }

    public function withWarning(string $message): static
    {
        return $this->flash('warning', $message);
    }

    public function withInfo(string $message): static
    {
        return $this->flash('info', $message);
    }

    /**
     * Flash validation errors so the form can re-display them.
     *
     * @param array<string,list<string>> $errors
     */
    public function withErrors(array $errors): static
    {
        return $this->flash('_errors', $errors);
    }

    /**
     * Flash the submitted input so the form can be re-populated, minus any
     * password field, which must never survive a redirect.
     *
     * @param array<string,mixed> $input
     */
    public function withInput(array $input): static
    {
        $sensitive = ['password', 'password_confirmation', 'current_password', 'new_password', '_csrf_token'];

        return $this->flash('_old_input', array_diff_key($input, array_flip($sensitive)));
    }

    private function flash(string $key, mixed $value): static
    {
        $this->session?->flash($key, $value);

        return $this;
    }

    /**
     * Reject an absolute URL pointing at a foreign host, which would turn the
     * redirect into an open redirect usable for phishing.
     */
    private function sanitiseLocation(string $location): string
    {
        if ($location === '') {
            return '/';
        }

        // Protocol-relative URLs ("//evil.example") leave the application.
        if (str_starts_with($location, '//')) {
            return '/';
        }

        if (preg_match('#^https?://#i', $location) === 1) {
            $appHost      = parse_url((string) config('app.url', ''), PHP_URL_HOST);
            $locationHost = parse_url($location, PHP_URL_HOST);

            if ($appHost === null || $locationHost === null || strcasecmp((string) $appHost, (string) $locationHost) !== 0) {
                return '/';
            }
        }

        // Strip CR/LF to prevent header injection.
        return (string) preg_replace('/[\r\n]/', '', $location);
    }
}
