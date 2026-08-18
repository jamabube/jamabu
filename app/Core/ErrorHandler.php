<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Http\JsonResponse;
use App\Core\Http\RedirectResponse;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Logging\Logger;
use App\Core\Security\AuthGuard;
use App\Core\View\ViewEngine;
use App\Exceptions\AuthenticationException;
use App\Exceptions\AuthorizationException;
use App\Exceptions\CsrfTokenException;
use App\Exceptions\RateLimitException;
use App\Exceptions\ValidationException;
use App\Exceptions\VamsException;
use App\Responses\ApiResponse;
use App\Services\ErrorLogService;
use ErrorException;
use Throwable;

/**
 * Converts any throwable into a safe HTTP response.
 *
 * Three guarantees the specification insists on are implemented here:
 *
 *   1. The application never terminates on an unhandled exception — every
 *      throwable, error and fatal shutdown lands in this class.
 *   2. Every failure is logged with enough detail for an administrator, and
 *      persisted to error_logs when the database is reachable.
 *   3. Nothing internal reaches the client. Expected conditions return their
 *      own message; anything unexpected returns a generic sentence plus a
 *      reference number the administrator can search for.
 *
 * @package App\Core
 * @version 1.0.0
 */
class ErrorHandler
{
    private ?Request $request = null;

    public function __construct(
        private readonly Application $app,
        private readonly Logger $logger,
        private readonly ViewEngine $view
    ) {
    }

    /**
     * Install the handler for exceptions, PHP errors and fatal shutdowns.
     */
    public function register(): void
    {
        set_exception_handler(function (Throwable $e): void {
            $this->renderAndSend($e);
        });

        // PHP notices and warnings become exceptions so they cannot be
        // silently ignored, but only when they are not suppressed with @.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new ErrorException($message, 0, $severity, $file, $line);
        });

        register_shutdown_function(function (): void {
            $error = error_get_last();

            if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
                return;
            }

            $this->renderAndSend(new ErrorException(
                $error['message'],
                0,
                $error['type'],
                $error['file'],
                $error['line']
            ));
        });
    }

    /**
     * Bind the request being served so error pages can honour content
     * negotiation and record the correlation id.
     */
    public function setRequest(?Request $request): void
    {
        $this->request = $request;
    }

    /**
     * Handle a throwable and emit the response.
     */
    private function renderAndSend(Throwable $e): void
    {
        try {
            $this->render($e)->send();
        } catch (Throwable $fallback) {
            // The error handler itself failed. Emit the barest possible
            // response rather than a blank page.
            error_log('[VAMS] Error handler failure: ' . $fallback->getMessage());

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/plain; charset=UTF-8');
            }

            echo 'A fatal error occurred and could not be rendered. Please contact your administrator.';
        }
    }

    /**
     * Convert a throwable into a response.
     */
    public function render(Throwable $e): Response
    {
        $reference = $this->log($e);

        if ($e instanceof ValidationException) {
            return $this->renderValidation($e);
        }

        if ($e instanceof AuthenticationException) {
            return $this->renderAuthentication($e);
        }

        if ($e instanceof CsrfTokenException) {
            return $this->renderCsrf($e);
        }

        if ($e instanceof RateLimitException) {
            return $this->wantsJson()
                ? ApiResponse::rateLimited($e->retryAfter(), $e->getMessage())
                : $this->errorPage(429, 'Too many requests', $e->getMessage(), $reference)
                    ->setHeader('Retry-After', (string) $e->retryAfter());
        }

        if ($e instanceof VamsException) {
            return $this->renderVamsException($e, $reference);
        }

        return $this->renderUnexpected($e, $reference);
    }

    private function renderValidation(ValidationException $e): Response
    {
        if ($this->wantsJson()) {
            return ApiResponse::validationFailed($e->errors(), $e->getMessage());
        }

        $session = $this->app->make(Session::class);
        $back    = $this->request?->header('referer') ?? '/';

        return (new RedirectResponse($back, 302, $session))
            ->withErrors($e->errors())
            ->withInput($this->request?->all() ?? [])
            ->withError($e->firstMessage());
    }

    private function renderAuthentication(AuthenticationException $e): Response
    {
        if ($this->wantsJson()) {
            return ApiResponse::error($e->errorCode(), $e->safeMessage(), $e->statusCode());
        }

        $session = $this->app->make(Session::class);

        // Remember where the user was headed so login can return them there.
        $intended = $this->request?->fullUrl();
        if ($intended !== null && $intended !== '/login' && !str_starts_with($intended, '/api/')) {
            $session->put('_intended_url', $intended);
        }

        return (new RedirectResponse('/login', 302, $session))->withError($e->safeMessage());
    }

    private function renderCsrf(CsrfTokenException $e): Response
    {
        if ($this->wantsJson()) {
            return ApiResponse::error($e->errorCode(), $e->safeMessage(), $e->statusCode());
        }

        $session = $this->app->make(Session::class);
        $back    = $this->request?->header('referer') ?? '/';

        return (new RedirectResponse($back, 302, $session))->withError($e->safeMessage());
    }

    private function renderVamsException(VamsException $e, string $reference): Response
    {
        if ($this->wantsJson()) {
            return ApiResponse::error($e->errorCode(), $e->safeMessage(), $e->statusCode(), $e->details());
        }

        return $this->errorPage(
            $e->statusCode(),
            $this->titleFor($e->statusCode()),
            $e->safeMessage(),
            $reference
        );
    }

    private function renderUnexpected(Throwable $e, string $reference): Response
    {
        $debug = $this->app->isDebug();

        $message = $debug
            ? sprintf('%s: %s', $e::class, $e->getMessage())
            : 'An internal error prevented this request from completing. Quote reference ' . $reference . ' when reporting it.';

        if ($this->wantsJson()) {
            $details = $debug
                ? [
                    'exception' => $e::class,
                    'file'      => $e->getFile(),
                    'line'      => $e->getLine(),
                    'trace'     => explode(PHP_EOL, Logger::formatTrace($e, 15)),
                ]
                : ['reference' => $reference];

            return ApiResponse::error('INTERNAL_ERROR', $message, 500, $details);
        }

        return $this->errorPage(500, 'Internal server error', $message, $reference, $debug ? $e : null);
    }

    /**
     * Render the HTML error page, falling back to plain text when even the
     * template cannot be rendered.
     */
    private function errorPage(
        int $status,
        string $title,
        string $message,
        string $reference,
        ?Throwable $exception = null
    ): Response {
        try {
            $html = $this->view->render('errors/error', [
                'status'    => $status,
                'title'     => $title,
                'message'   => $message,
                'reference' => $reference,
                'exception' => $exception,
                'requestId' => $this->request?->requestId() ?? '',
            ]);

            return Response::html($html, $status);
        } catch (Throwable) {
            return Response::html(
                sprintf(
                    '<!doctype html><meta charset="utf-8"><title>%1$d %2$s</title>'
                    . '<body style="font-family:system-ui,sans-serif;padding:3rem;max-width:40rem;margin:auto">'
                    . '<h1>%1$d &mdash; %2$s</h1><p>%3$s</p><p style="color:#666">Reference: %4$s</p></body>',
                    $status,
                    htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
                    htmlspecialchars($reference, ENT_QUOTES, 'UTF-8')
                ),
                $status
            );
        }
    }

    /**
     * Write the failure to the log file and, when possible, to error_logs.
     *
     * @return string The reference number quoted to the user.
     */
    private function log(Throwable $e): string
    {
        $reference = strtoupper(substr(bin2hex(random_bytes(6)), 0, 12));

        $context = [
            'reference'  => $reference,
            'request_id' => $this->request?->requestId(),
            'method'     => $this->request?->method(),
            'path'       => $this->request?->path(),
            'ip'         => $this->request?->ip(),
            'user_agent' => $this->request?->userAgent(),
        ];

        $severity = $e instanceof VamsException ? $e->severity() : 'error';

        if ($e instanceof VamsException) {
            $context += $e->context();
        }

        // Expected conditions are noise at error level; log them as notices.
        if (in_array($severity, ['notice', 'warning'], true)) {
            $this->logger->channel('error')->log(
                \App\Core\Logging\LogLevel::parse($severity),
                $e->getMessage(),
                $context + ['exception' => $e::class]
            );
        } else {
            $this->logger->channel('error')->exception($e, $context);
        }

        $this->persist($e, $reference, $severity);

        return $reference;
    }

    /**
     * Persist the error to the database.
     *
     * Wrapped in its own try/catch: when the failure *is* the database, the
     * attempt to record it must not replace the original error.
     */
    private function persist(Throwable $e, string $reference, string $severity): void
    {
        if ($severity === 'notice') {
            return;
        }

        try {
            $auth = $this->app->make(AuthGuard::class);

            $this->app->make(ErrorLogService::class)->record(
                exception: $e,
                reference: $reference,
                severity: $severity,
                module: $this->moduleFor(),
                userId: $auth->id(),
                deviceId: $auth->deviceId(),
                requestId: $this->request?->requestId() ?? '',
                path: $this->request?->path() ?? '',
                ipAddress: $this->request?->ip() ?? ''
            );
        } catch (Throwable $persistFailure) {
            $this->logger->channel('error')->warning('Error log could not be persisted', [
                'reference' => $reference,
                'reason'    => $persistFailure->getMessage(),
            ]);
        }
    }

    /**
     * Derive the module name from the request path, for error-log grouping.
     */
    private function moduleFor(): string
    {
        $path = trim($this->request?->path() ?? '', '/');

        if ($path === '') {
            return 'dashboard';
        }

        $segments = explode('/', $path);

        // "/api/v1/vehicles/12" reports as "vehicles".
        if ($segments[0] === 'api') {
            return $segments[2] ?? 'api';
        }

        return $segments[0];
    }

    private function wantsJson(): bool
    {
        return $this->request?->expectsJson() ?? (PHP_SAPI !== 'cli' && !isset($_SERVER['HTTP_ACCEPT']));
    }

    private function titleFor(int $status): string
    {
        return match ($status) {
            400 => 'Bad request',
            401 => 'Authentication required',
            403 => 'Access denied',
            404 => 'Page not found',
            405 => 'Method not allowed',
            409 => 'Conflict',
            413 => 'Request too large',
            415 => 'Unsupported media type',
            419 => 'Session expired',
            422 => 'Validation failed',
            423 => 'Account locked',
            429 => 'Too many requests',
            503 => 'Service unavailable',
            default => 'Something went wrong',
        };
    }
}
