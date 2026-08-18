<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * HTTP response value object.
 *
 * Headers and cookies are buffered here and only written when send() runs, so
 * middleware can still adjust them after a controller has returned.
 *
 * @package App\Core\Http
 * @version 1.0.0
 */
class Response
{
    /** @var array<string,string> */
    protected array $headers = [];

    /** @var list<array{name:string,value:string,options:array<string,mixed>}> */
    protected array $cookies = [];

    protected string $content;
    protected int $status;

    /** Human-readable reason phrases for the status codes the system emits. */
    private const REASON_PHRASES = [
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        204 => 'No Content',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        408 => 'Request Timeout',
        409 => 'Conflict',
        413 => 'Payload Too Large',
        415 => 'Unsupported Media Type',
        419 => 'Authentication Timeout',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        429 => 'Too Many Requests',
        500 => 'Internal Server Error',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
    ];

    /**
     * @param array<string,string> $headers
     */
    public function __construct(string $content = '', int $status = 200, array $headers = [])
    {
        $this->content = $content;
        $this->status  = $status;

        foreach ($headers as $name => $value) {
            $this->headers[strtolower($name)] = $value;
        }
    }

    public static function html(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    public static function text(string $content, int $status = 200): self
    {
        return new self($content, $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    public static function noContent(): self
    {
        return new self('', 204);
    }

    /**
     * Build a file download response.
     */
    public static function download(string $content, string $filename, string $contentType = 'application/octet-stream'): self
    {
        // The filename is sanitised because it becomes part of a header value.
        $safeName = preg_replace('/[^A-Za-z0-9._\- ]/', '', $filename) ?: 'download';

        return new self($content, 200, [
            'Content-Type'              => $contentType,
            'Content-Disposition'       => 'attachment; filename="' . $safeName . '"',
            'Content-Length'            => (string) strlen($content),
            'X-Content-Type-Options'    => 'nosniff',
            'Cache-Control'             => 'private, no-store',
        ]);
    }

    public function setHeader(string $name, string $value): static
    {
        $this->headers[strtolower($name)] = $value;

        return $this;
    }

    /**
     * @param array<string,string> $headers
     */
    public function withHeaders(array $headers): static
    {
        foreach ($headers as $name => $value) {
            $this->setHeader($name, $value);
        }

        return $this;
    }

    public function removeHeader(string $name): static
    {
        unset($this->headers[strtolower($name)]);

        return $this;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * @return array<string,string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Queue a cookie for the response.
     *
     * @param array<string,mixed> $options
     */
    public function withCookie(string $name, string $value, array $options = []): static
    {
        $this->cookies[] = [
            'name'    => $name,
            'value'   => $value,
            'options' => $options + [
                'expires'  => 0,
                'path'     => '/',
                'domain'   => '',
                'secure'   => (bool) config('session.cookie.secure', true),
                'httponly' => true,
                'samesite' => (string) config('session.cookie.same_site', 'Lax'),
            ],
        ];

        return $this;
    }

    public function setStatus(int $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function setContent(string $content): static
    {
        $this->content = $content;

        return $this;
    }

    public function content(): string
    {
        return $this->content;
    }

    public function isSuccessful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }

    /**
     * Emit the response to the client.
     *
     * Output buffering is discarded first: any stray output produced before
     * this point (a warning, a stray echo) must never corrupt a JSON body.
     */
    public function send(bool $withBody = true): void
    {
        if (!headers_sent()) {
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            $reason = self::REASON_PHRASES[$this->status] ?? 'Unknown Status';
            header(sprintf('HTTP/1.1 %d %s', $this->status, $reason), true, $this->status);

            foreach ($this->headers as $name => $value) {
                header($this->canonicalHeaderName($name) . ': ' . $value, true);
            }

            foreach ($this->cookies as $cookie) {
                /** @var array{expires:int,path:string,domain:string,secure:bool,httponly:bool,samesite:string} $options */
                $options = $cookie['options'];
                setcookie($cookie['name'], $cookie['value'], $options);
            }
        }

        // 204 and 304 responses must not carry a body.
        if ($withBody && $this->status !== 204 && $this->status !== 304) {
            echo $this->content;
        }
    }

    /**
     * Convert "content-type" to "Content-Type".
     */
    private function canonicalHeaderName(string $name): string
    {
        return implode('-', array_map(
            static fn (string $part): string => ucfirst($part),
            explode('-', $name)
        ));
    }
}
