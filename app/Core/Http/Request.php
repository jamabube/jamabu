<?php

declare(strict_types=1);

namespace App\Core\Http;

use App\Core\Support\Arr;
use App\Core\Support\Str;
use App\Exceptions\InvalidRequestException;

/**
 * Immutable-by-convention representation of an inbound HTTP request.
 *
 * Constructed once per request from the superglobals and then passed through
 * the middleware pipeline. Nothing downstream reads $_GET/$_POST directly, so
 * sanitisation applied here is guaranteed to be applied everywhere.
 *
 * @package App\Core\Http
 * @version 1.0.0
 */
class Request
{
    /** @var array<string,string> Normalised header map (lower-case names). */
    private array $headers;

    /** @var array<string,mixed> */
    private array $query;

    /** @var array<string,mixed> */
    private array $body;

    /** @var array<string,mixed> Server parameters. */
    private array $server;

    /** @var array<string,UploadedFile> */
    private array $files;

    /** @var array<string,string> */
    private array $cookies;

    /** @var array<string,mixed> Attributes attached by middleware. */
    private array $attributes = [];

    /** @var array<string,string> Parameters extracted from the matched route. */
    private array $routeParameters = [];

    private string $method;
    private string $path;
    private string $rawBody;
    private string $requestId;
    private float $startedAt;

    /**
     * @param array<string,mixed>        $query
     * @param array<string,mixed>        $body
     * @param array<string,mixed>        $server
     * @param array<string,UploadedFile> $files
     * @param array<string,string>       $cookies
     */
    public function __construct(
        string $method,
        string $path,
        array $query = [],
        array $body = [],
        array $server = [],
        array $files = [],
        array $cookies = [],
        string $rawBody = ''
    ) {
        $this->method    = strtoupper($method);
        $this->path      = '/' . trim($path, '/');
        $this->query     = $query;
        $this->body      = $body;
        $this->server    = $server;
        $this->files     = $files;
        $this->cookies   = $cookies;
        $this->rawBody   = $rawBody;
        $this->headers   = $this->extractHeaders($server);
        $this->startedAt = (float) ($server['REQUEST_TIME_FLOAT'] ?? microtime(true));

        // A client-supplied correlation id is honoured only when it is a safe
        // token; otherwise the server mints its own.
        $supplied = $this->headers['x-request-id'] ?? '';
        $this->requestId = preg_match('/^[A-Za-z0-9\-_]{8,64}$/', $supplied) === 1
            ? $supplied
            : Str::uuid();
    }

    /**
     * Build a request from the PHP superglobals.
     *
     * @throws InvalidRequestException When the body is malformed or oversized.
     */
    public static function capture(): self
    {
        $server = $_SERVER;
        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri    = (string) ($server['REQUEST_URI'] ?? '/');
        $path   = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');

        // Strip a sub-directory prefix when the application is not deployed at
        // the document root (for example http://host/vams/public).
        $scriptName = (string) ($server['SCRIPT_NAME'] ?? '');
        $basePath   = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
        if ($basePath !== '' && $basePath !== '/' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        $rawBody = self::readRawBody();
        $body    = self::parseBody($method, $server, $rawBody);

        $request = new self(
            $method,
            $path === '' ? '/' : $path,
            $_GET,
            $body,
            $server,
            UploadedFile::fromGlobals($_FILES),
            array_map('strval', $_COOKIE),
            $rawBody
        );

        // HTML forms can only issue GET and POST; a _method field promotes a
        // POST to the verb the route actually declares.
        if ($method === 'POST' && isset($body['_method'])) {
            $override = strtoupper((string) $body['_method']);
            if (in_array($override, ['PUT', 'PATCH', 'DELETE'], true)) {
                $request->method = $override;
            }
        }

        return $request;
    }

    /**
     * Read php://input, enforcing the configured maximum body size.
     *
     * @throws InvalidRequestException
     */
    private static function readRawBody(): string
    {
        $limit    = (int) config('security.sanitisation.max_body_bytes', 2097152);
        $declared = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($limit > 0 && $declared > $limit) {
            throw InvalidRequestException::payloadTooLarge($declared, $limit);
        }

        $raw = file_get_contents('php://input');
        if ($raw === false) {
            return '';
        }

        if ($limit > 0 && strlen($raw) > $limit) {
            throw InvalidRequestException::payloadTooLarge(strlen($raw), $limit);
        }

        return $raw;
    }

    /**
     * Decode the request body according to its content type.
     *
     * @param array<string,mixed> $server
     *
     * @return array<string,mixed>
     *
     * @throws InvalidRequestException
     */
    private static function parseBody(string $method, array $server, string $rawBody): array
    {
        $contentType = strtolower((string) ($server['CONTENT_TYPE'] ?? $server['HTTP_CONTENT_TYPE'] ?? ''));

        if (str_contains($contentType, 'application/json')) {
            if (trim($rawBody) === '') {
                return [];
            }

            try {
                /** @var mixed $decoded */
                $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                throw InvalidRequestException::malformedJson($e->getMessage());
            }

            if (!is_array($decoded)) {
                throw InvalidRequestException::malformedJson('The JSON body must decode to an object.');
            }

            return $decoded;
        }

        if ($method === 'POST') {
            return $_POST;
        }

        // PUT/PATCH/DELETE with a form-encoded body are not parsed by PHP.
        if (str_contains($contentType, 'application/x-www-form-urlencoded') && $rawBody !== '') {
            $parsed = [];
            parse_str($rawBody, $parsed);

            return $parsed;
        }

        return $_POST;
    }

    /**
     * Normalise the CGI server array into a header map.
     *
     * @param array<string,mixed> $server
     *
     * @return array<string,string>
     */
    private function extractHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) {
                continue;
            }

            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = (string) $value;
                continue;
            }

            if (in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true)) {
                $headers[strtolower(str_replace('_', '-', $key))] = (string) $value;
            }
        }

        return $headers;
    }

    // ------------------------------------------------------------------
    // Basic accessors
    // ------------------------------------------------------------------

    public function method(): string
    {
        return $this->method;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * The full request URI including the query string.
     */
    public function fullUrl(): string
    {
        $query = $this->query === [] ? '' : '?' . http_build_query($this->query);

        return $this->path . $query;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    public function requestId(): string
    {
        return $this->requestId;
    }

    public function startedAt(): float
    {
        return $this->startedAt;
    }

    /**
     * Elapsed wall-clock time since the request arrived, in milliseconds.
     */
    public function elapsedMs(): float
    {
        return round((microtime(true) - $this->startedAt) * 1000, 2);
    }

    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * @return array<string,string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    public function cookie(string $name, ?string $default = null): ?string
    {
        return $this->cookies[$name] ?? $default;
    }

    public function server(string $key, mixed $default = null): mixed
    {
        return $this->server[$key] ?? $default;
    }

    public function userAgent(): string
    {
        return Str::limit($this->header('user-agent', '') ?? '', 512, '');
    }

    /**
     * Resolve the client IP address, honouring trusted proxies only.
     */
    public function ip(): string
    {
        $remote = (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');

        /** @var list<string> $trusted */
        $trusted = (array) config('security.trusted_proxies', []);

        if ($trusted !== [] && in_array($remote, $trusted, true)) {
            $forwarded = $this->header('x-forwarded-for');
            if ($forwarded !== null && $forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);
                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        return filter_var($remote, FILTER_VALIDATE_IP) !== false ? $remote : '0.0.0.0';
    }

    public function isSecure(): bool
    {
        if (($this->server['HTTPS'] ?? '') !== '' && strtolower((string) $this->server['HTTPS']) !== 'off') {
            return true;
        }

        if ((int) ($this->server['SERVER_PORT'] ?? 0) === 443) {
            return true;
        }

        /** @var list<string> $trusted */
        $trusted = (array) config('security.trusted_proxies', []);
        $remote  = (string) ($this->server['REMOTE_ADDR'] ?? '');

        return $trusted !== []
            && in_array($remote, $trusted, true)
            && strtolower((string) $this->header('x-forwarded-proto', '')) === 'https';
    }

    // ------------------------------------------------------------------
    // Input access
    // ------------------------------------------------------------------

    /**
     * Read an input value from the body first, then the query string.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->body)) {
            return $this->body[$key];
        }

        if (array_key_exists($key, $this->query)) {
            return $this->query[$key];
        }

        return Arr::get($this->body, $key, Arr::get($this->query, $key, $default));
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    public function post(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function integer(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) (is_scalar($value) ? $value : '')), ['1', 'true', 'on', 'yes'], true);
    }

    /**
     * @return list<mixed>
     */
    public function array(string $key): array
    {
        $value = $this->input($key, []);

        return is_array($value) ? array_values($value) : [];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->body) || array_key_exists($key, $this->query);
    }

    public function filled(string $key): bool
    {
        $value = $this->input($key);

        return $value !== null && $value !== '' && $value !== [];
    }

    /**
     * Every input value, body taking precedence over the query string.
     *
     * @return array<string,mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->body);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string,mixed>
     */
    public function only(array $keys): array
    {
        return Arr::only($this->all(), $keys);
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string,mixed>
     */
    public function except(array $keys): array
    {
        return Arr::except($this->all(), $keys);
    }

    /**
     * Replace the parsed input. Used by the sanitisation middleware.
     *
     * @param array<string,mixed> $query
     * @param array<string,mixed> $body
     */
    public function replaceInput(array $query, array $body): void
    {
        $this->query = $query;
        $this->body  = $body;
    }

    // ------------------------------------------------------------------
    // Files
    // ------------------------------------------------------------------

    public function file(string $key): ?UploadedFile
    {
        return $this->files[$key] ?? null;
    }

    public function hasFile(string $key): bool
    {
        return isset($this->files[$key]) && $this->files[$key]->isValid();
    }

    /**
     * @return array<string,UploadedFile>
     */
    public function files(): array
    {
        return $this->files;
    }

    // ------------------------------------------------------------------
    // Content negotiation
    // ------------------------------------------------------------------

    public function isJson(): bool
    {
        return str_contains(strtolower($this->header('content-type', '') ?? ''), 'json');
    }

    /**
     * True when the client expects a JSON body rather than an HTML page.
     */
    public function expectsJson(): bool
    {
        if ($this->isApiRequest()) {
            return true;
        }

        if ($this->isAjax()) {
            return true;
        }

        $accept = strtolower($this->header('accept', '') ?? '');

        return str_contains($accept, 'application/json') && !str_contains($accept, 'text/html');
    }

    public function isAjax(): bool
    {
        return strtolower($this->header('x-requested-with', '') ?? '') === 'xmlhttprequest';
    }

    public function isApiRequest(): bool
    {
        return str_starts_with($this->path, '/api/');
    }

    /**
     * Extract a bearer token from the Authorization header.
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('authorization', '') ?? '';

        if (stripos($header, 'bearer ') === 0) {
            $token = trim(substr($header, 7));

            return $token === '' ? null : $token;
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Attributes and route parameters
    // ------------------------------------------------------------------

    public function setAttribute(string $key, mixed $value): void
    {
        $this->attributes[$key] = $value;
    }

    public function attribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    /**
     * @return array<string,mixed>
     */
    public function attributes(): array
    {
        return $this->attributes;
    }

    /**
     * @param array<string,string> $parameters
     */
    public function setRouteParameters(array $parameters): void
    {
        $this->routeParameters = $parameters;
    }

    public function route(string $key, ?string $default = null): ?string
    {
        return $this->routeParameters[$key] ?? $default;
    }

    public function routeInt(string $key, int $default = 0): int
    {
        $value = $this->routeParameters[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * @return array<string,string>
     */
    public function routeParameters(): array
    {
        return $this->routeParameters;
    }
}
