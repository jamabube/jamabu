<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Logging\Logger;
use App\Core\Support\Arr;
use App\Exceptions\VamsException;
use App\Repositories\ErrorLogRepository;
use Throwable;

/**
 * Persists application errors for administrative review.
 *
 * Called by the error handler for every unexpected failure. The write is
 * best-effort by design: when the failure being recorded *is* a database
 * fault, the attempt to record it must not replace the original error with a
 * second one.
 *
 * @package App\Services
 * @version 1.0.0
 */
class ErrorLogService
{
    public function __construct(
        private readonly ErrorLogRepository $repository,
        private readonly Logger $logger
    ) {
    }

    /**
     * Store one occurrence of a failure.
     *
     * @return string The reference the user is asked to quote.
     */
    public function record(
        Throwable $exception,
        string $reference,
        string $severity,
        string $module,
        ?int $userId = null,
        ?int $deviceId = null,
        string $requestId = '',
        string $path = '',
        string $ipAddress = ''
    ): string {
        [$controller, $method] = $this->originFrame($exception);

        $context = $exception instanceof VamsException ? $exception->context() : [];
        $now     = now()->format('Y-m-d H:i:s');

        $result = $this->repository->recordOccurrence([
            'reference'       => $reference,
            'module'          => mb_substr($module, 0, 50),
            'controller'      => $controller,
            'method'          => $method,
            'severity'        => $this->normaliseSeverity($severity),
            'exception_class' => mb_substr($exception::class, 0, 150),
            'message'         => $exception->getMessage(),
            'file'            => mb_substr($exception->getFile(), 0, 255),
            'line'            => $exception->getLine(),
            'stack_trace'     => Logger::formatTrace($exception),
            'context'         => $this->encode(Arr::redact($context, (array) config('logging.redact', []))),
            'user_id'         => $userId,
            'device_id'       => $deviceId,
            'ip_address'      => $ipAddress === '' ? null : $ipAddress,
            'request_id'      => $requestId === '' ? null : $requestId,
            'request_method'  => null,
            'request_path'    => $path === '' ? null : mb_substr($path, 0, 255),
            'fingerprint'     => $this->fingerprint($exception),
            'first_seen_at'   => $now,
            'last_seen_at'    => $now,
            'created_at'      => $now,
        ]);

        // When the failure folded onto an existing row, the caller must quote
        // that row's reference, not the one just generated, or the search will
        // find nothing.
        return $result['reference'];
    }

    /**
     * Group identical failures.
     *
     * The message is excluded on purpose: two occurrences of the same bug often
     * differ only in an interpolated identifier, and folding them together is
     * exactly what makes the error log readable.
     */
    private function fingerprint(Throwable $exception): string
    {
        return hash('sha256', implode('|', [
            $exception::class,
            $exception->getFile(),
            (string) $exception->getLine(),
        ]));
    }

    /**
     * Locate the first application frame, which is far more useful than the
     * framework frame the exception was constructed in.
     *
     * @return array{0:string|null,1:string|null}
     */
    private function originFrame(Throwable $exception): array
    {
        foreach ($exception->getTrace() as $frame) {
            $class = $frame['class'] ?? null;

            if (!is_string($class)) {
                continue;
            }

            if (str_starts_with($class, 'App\\Controllers')
                || str_starts_with($class, 'App\\Services')
                || str_starts_with($class, 'App\\Repositories')
                || str_starts_with($class, 'App\\Middleware')) {
                return [mb_substr($class, 0, 120), mb_substr((string) ($frame['function'] ?? ''), 0, 60)];
            }
        }

        $first = $exception->getTrace()[0] ?? [];

        return [
            isset($first['class']) ? mb_substr((string) $first['class'], 0, 120) : null,
            isset($first['function']) ? mb_substr((string) $first['function'], 0, 60) : null,
        ];
    }

    /**
     * Map a severity onto one the column's enumeration accepts.
     */
    private function normaliseSeverity(string $severity): string
    {
        $allowed = ['notice', 'warning', 'error', 'critical', 'alert', 'emergency'];

        return in_array($severity, $allowed, true) ? $severity : 'error';
    }

    /**
     * @param array<string,mixed> $context
     */
    private function encode(array $context): ?string
    {
        if ($context === []) {
            return null;
        }

        $json = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? null : $json;
    }

    /**
     * Mark an error resolved with a note from the administrator.
     */
    public function resolve(int $errorLogId, int $resolvedBy, string $notes): void
    {
        $this->repository->resolve($errorLogId, $resolvedBy, $notes);

        $this->logger->channel('application')->info('Error log resolved', [
            'error_log_id' => $errorLogId,
            'resolved_by'  => $resolvedBy,
        ]);
    }
}
