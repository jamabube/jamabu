<?php

declare(strict_types=1);

namespace App\Core\Logging;

use App\Core\Support\Arr;
use Throwable;

/**
 * Centralised logger.
 *
 * Writes newline-delimited JSON so that log files remain machine-readable for
 * later analysis, and applies the configured redaction list to every context
 * payload — a password or API key must never be recoverable from a log file.
 *
 * Database-backed channels (audit, security, error, api) are handled by the
 * corresponding services; this class owns the filesystem side and is safe to
 * call before the database connection exists, which matters during bootstrap
 * failures.
 *
 * @package App\Core\Logging
 * @version 1.0.0
 */
class Logger
{
    private LogLevel $threshold;

    /** Channel currently selected by channel(). */
    private string $channel = 'application';

    /** @var array<string,mixed> Context merged into every record. */
    private array $sharedContext = [];

    /** @var list<string> */
    private array $redactKeys;

    /** @var array<string,array{path:string,database:bool}> */
    private array $channels;

    private bool $toFile;

    private string $basePath;

    /**
     * @param array<string,mixed> $config Contents of config/logging.php.
     */
    public function __construct(array $config, string $basePath)
    {
        $this->threshold  = LogLevel::parse((string) ($config['level'] ?? 'info'));
        $this->toFile     = (bool) ($config['to_file'] ?? true);
        /** @var array<string,array{path:string,database:bool}> $channels */
        $channels         = $config['channels'] ?? [];
        $this->channels   = $channels;
        /** @var list<string> $redact */
        $redact           = $config['redact'] ?? [];
        $this->redactKeys = $redact;
        $this->basePath   = rtrim($basePath, '/\\');
    }

    /**
     * Select the channel for the next write. Returns a clone so that
     * `logger()->channel('security')` never mutates shared state.
     */
    public function channel(string $channel): self
    {
        $clone = clone $this;
        $clone->channel = $channel;

        return $clone;
    }

    /**
     * Attach context merged into every subsequent record from this instance.
     *
     * @param array<string,mixed> $context
     */
    public function withContext(array $context): self
    {
        $clone = clone $this;
        $clone->sharedContext = array_merge($this->sharedContext, $context);

        return $clone;
    }

    /** @param array<string,mixed> $context */
    public function debug(string $message, array $context = []): void
    {
        $this->log(LogLevel::Debug, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function info(string $message, array $context = []): void
    {
        $this->log(LogLevel::Info, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function notice(string $message, array $context = []): void
    {
        $this->log(LogLevel::Notice, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function warning(string $message, array $context = []): void
    {
        $this->log(LogLevel::Warning, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function error(string $message, array $context = []): void
    {
        $this->log(LogLevel::Error, $message, $context);
    }

    /** @param array<string,mixed> $context */
    public function critical(string $message, array $context = []): void
    {
        $this->log(LogLevel::Critical, $message, $context);
    }

    /**
     * Record a throwable together with its stack trace.
     *
     * @param array<string,mixed> $context
     */
    public function exception(Throwable $exception, array $context = []): void
    {
        $this->log(LogLevel::Error, $exception->getMessage(), array_merge($context, [
            'exception' => $exception::class,
            'file'      => $exception->getFile(),
            'line'      => $exception->getLine(),
            'trace'     => self::formatTrace($exception),
        ]));
    }

    /**
     * Write one record.
     *
     * Logging must never be able to break the request that triggered it, so
     * every failure inside this method is swallowed after a best-effort
     * fallback to PHP's own error log.
     *
     * @param array<string,mixed> $context
     */
    public function log(LogLevel $level, string $message, array $context = []): void
    {
        if (!$level->passes($this->threshold) || !$this->toFile) {
            return;
        }

        try {
            $record = [
                'timestamp' => now()->format('Y-m-d\TH:i:s.vP'),
                'level'     => $level->value,
                'channel'   => $this->channel,
                'message'   => $message,
                'context'   => Arr::redact(array_merge($this->sharedContext, $context), $this->redactKeys),
                'pid'       => getmypid(),
            ];

            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            if ($line === false) {
                return;
            }

            $this->write($line . PHP_EOL);
        } catch (Throwable $e) {
            error_log(sprintf('[VAMS logger failure] %s | original: %s', $e->getMessage(), $message));
        }
    }

    /**
     * Append a line to the current channel's daily file.
     */
    private function write(string $line): void
    {
        $directory = $this->basePath . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, (string) ($this->channels[$this->channel]['path'] ?? 'storage/logs/system'));

        if (!is_dir($directory) && !mkdir($directory, 0o750, true) && !is_dir($directory)) {
            return;
        }

        $file = $directory . DIRECTORY_SEPARATOR . $this->channel . '-' . now()->format('Y-m-d') . '.log';

        // LOCK_EX keeps concurrent workers from interleaving partial lines.
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Render a throwable's stack trace, truncated so a single record cannot
     * fill the disk.
     */
    public static function formatTrace(Throwable $exception, int $maxFrames = 30): string
    {
        $frames = array_slice($exception->getTrace(), 0, $maxFrames);
        $lines  = [];

        foreach ($frames as $index => $frame) {
            $lines[] = sprintf(
                '#%d %s(%s): %s%s%s()',
                $index,
                $frame['file'] ?? '[internal]',
                $frame['line'] ?? '0',
                $frame['class'] ?? '',
                $frame['type'] ?? '',
                $frame['function'] ?? ''
            );
        }

        if (count($exception->getTrace()) > $maxFrames) {
            $lines[] = sprintf('... %d more frame(s) omitted', count($exception->getTrace()) - $maxFrames);
        }

        return implode(PHP_EOL, $lines);
    }

    /**
     * Whether the given channel also persists to the database.
     */
    public function channelUsesDatabase(string $channel): bool
    {
        return (bool) ($this->channels[$channel]['database'] ?? false);
    }

    public function threshold(): LogLevel
    {
        return $this->threshold;
    }
}
