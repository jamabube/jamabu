<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Database\Connection;
use App\Core\Database\MigrationRunner;
use App\Core\ErrorHandler;
use App\Core\Http\Kernel;
use App\Core\Http\Request;
use App\Core\Logging\Logger;
use App\Core\Routing\Router;
use App\Core\View\ViewEngine;
use App\Middleware\ForceHttpsMiddleware;
use App\Repositories\UserRepository;
use Throwable;

/**
 * Check that this installation can actually serve a page.
 *
 * Every check here exists because its absence once produced a blank browser
 * error with the cause somewhere else entirely: a database that answered on
 * the port but refused the schema, a template reading a key its controller
 * never supplied, an HTTPS redirect pointing back at the address it had just
 * refused. A launcher that reports "Ready" and hands over to a browser is not
 * telling the operator anything it could not have found out first.
 *
 * The last check renders the sign-in page through the real kernel, which is
 * the only one that proves the answer rather than inferring it.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class DoctorCommand extends Command
{
    protected string $name = 'doctor';
    protected string $description = 'Check that the installation can serve a page, and say what is wrong when it cannot.';
    protected string $usage = 'php bin/console doctor [--quiet-when-well]';

    private const PASS = 'pass';
    private const WARN = 'warn';
    private const FAIL = 'fail';

    /** @var list<array{state:string,check:string,detail:string}> */
    private array $results = [];

    public function handle(): int
    {
        $this->checkRuntime();
        $this->checkEnvironment();

        // Everything past this point needs the database, and reporting a
        // dozen consequences of one unreachable server helps nobody.
        if ($this->checkDatabase()) {
            $this->checkSchema();
            $this->checkAdministrator();
        }

        $this->checkTransport();
        $this->checkPageRenders();

        return $this->report();
    }

    // ------------------------------------------------------------------
    // Checks
    // ------------------------------------------------------------------

    private function checkRuntime(): void
    {
        $this->add(
            version_compare(PHP_VERSION, '8.2.0', '>=') ? self::PASS : self::FAIL,
            'PHP version',
            PHP_VERSION . (version_compare(PHP_VERSION, '8.2.0', '>=') ? '' : ' — 8.2 or later is required')
        );

        $missing = array_values(array_filter(
            ['pdo_mysql', 'mbstring', 'json', 'openssl', 'fileinfo'],
            static fn (string $extension): bool => !extension_loaded($extension)
        ));

        $this->add(
            $missing === [] ? self::PASS : self::FAIL,
            'Required extensions',
            $missing === [] ? 'all present' : 'missing: ' . implode(', ', $missing)
        );

        $optional = array_values(array_filter(
            ['zip', 'gd', 'intl', 'curl'],
            static fn (string $extension): bool => !extension_loaded($extension)
        ));

        if ($optional !== []) {
            $this->add(self::WARN, 'Optional extensions', 'not loaded: ' . implode(', ', $optional));
        }
    }

    private function checkEnvironment(): void
    {
        $this->add(
            is_file($this->app->basePath('.env')) ? self::PASS : self::FAIL,
            'Environment file',
            is_file($this->app->basePath('.env')) ? '.env is present' : '.env is missing'
        );

        $key = (string) config('app.key', '');

        $this->add(
            $key !== '' ? self::PASS : self::FAIL,
            'Application key',
            $key !== '' ? 'set' : 'unset — run php bin/console key:generate'
        );
    }

    private function checkDatabase(): bool
    {
        try {
            $connection = $this->service(Connection::class);
            $connection->isHealthy();

            $this->add(self::PASS, 'Database', sprintf(
                'connected to "%s" as configured',
                $connection->databaseName()
            ));

            return true;
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Database', $this->reason($e));

            return false;
        }
    }

    private function checkSchema(): void
    {
        try {
            $runner = new MigrationRunner(
                $this->service(Connection::class),
                $this->app->basePath((string) config('database.migrations.path', 'database/migrations'))
            );

            if ($runner->isPartiallyMigrated()) {
                $this->add(
                    self::FAIL,
                    'Schema',
                    'tables exist that no migration accounts for — run php bin/console migrate:fresh --seed'
                );

                return;
            }

            $pending = array_values(array_filter(
                $runner->status(),
                static fn (array $entry): bool => !$entry['applied']
            ));

            $this->add(
                $pending === [] ? self::PASS : self::FAIL,
                'Schema',
                $pending === []
                    ? count($runner->status()) . ' migration(s) applied'
                    : count($pending) . ' migration(s) pending — run php bin/console migrate'
            );
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Schema', $this->reason($e));
        }
    }

    private function checkAdministrator(): void
    {
        try {
            $administrators = $this->service(UserRepository::class)->withRoles(['administrator']);

            $this->add(
                $administrators === [] ? self::FAIL : self::PASS,
                'Administrator',
                $administrators === []
                    ? 'no administrator account exists — run php bin/console seed'
                    : count($administrators) . ' account(s)'
            );
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Administrator', $this->reason($e));
        }
    }

    /**
     * The settings that decide whether a browser can reach the system at all.
     *
     * Enforcing HTTPS while APP_URL names an http address is the combination
     * that produces an endless redirect, and a cookie marked secure is never
     * returned over http, so a sign-in appears to succeed and bounces back to
     * the form. Both look like application faults from the browser.
     */
    private function checkTransport(): void
    {
        $url    = (string) config('app.url', '');
        $isHttp = str_starts_with($url, 'http://');

        if ($url === '') {
            $this->add(self::WARN, 'APP_URL', 'unset — absolute links and redirects cannot be built');

            return;
        }

        $this->add(self::PASS, 'APP_URL', $url);

        // A stored setting overlays the file configuration, so .env can say one
        // thing and the running system do another. An administrator editing
        // .env and seeing no change has no way to discover that on their own.
        $this->reportOverride('FORCE_HTTPS', 'security.transport.force_https');
        $this->reportOverride('SESSION_SECURE_COOKIE', 'session.cookie.secure');

        $enforcing = (bool) config('security.transport.force_https', true);
        $loopback  = ForceHttpsMiddleware::addressesLoopback();

        if ($isHttp && $enforcing && $loopback) {
            // The same rule the middleware applies, asked of the middleware
            // rather than restated here. Enforcement is skipped on a loopback
            // address, so the setting being on is not a fault — but it will
            // start mattering the moment this installation is given a real
            // address, which is worth knowing before that day.
            $this->add(
                self::WARN,
                'HTTPS enforcement',
                'on, but skipped because APP_URL is a local address — it will apply once this is served on a real host'
            );
        } elseif ($isHttp && $enforcing) {
            $this->add(
                self::FAIL,
                'HTTPS enforcement',
                'FORCE_HTTPS is on while APP_URL is http — the browser will be redirected in a loop'
            );
        }

        if ($isHttp && (bool) config('session.cookie.secure', true)) {
            $this->add(
                self::FAIL,
                'Session cookie',
                'marked secure while APP_URL is http — the cookie is never returned, so sign-in cannot hold'
            );
        }
    }

    /**
     * Note where a stored setting is overriding the environment file.
     *
     * Only when the two actually disagree: saying so every time would train
     * the reader to skip the line that matters.
     */
    private function reportOverride(string $variable, string $configKey): void
    {
        $fromEnv = env($variable);

        if ($fromEnv === null) {
            return;
        }

        $effective = (bool) config($configKey, false);

        if ((bool) $fromEnv === $effective) {
            return;
        }

        $this->add(self::WARN, $variable, sprintf(
            '.env says %s but the stored setting wins and it is %s — change it under Settings, Security',
            $fromEnv ? 'true' : 'false',
            $effective ? 'true' : 'false'
        ));
    }

    /**
     * Render the sign-in page through the real kernel.
     *
     * The only check that proves the application can answer rather than
     * inferring it from its parts. Notices are promoted to exceptions for the
     * duration, exactly as a served request does, so a template reading a key
     * nobody supplied fails here instead of in the browser.
     */
    private function checkPageRenders(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $errorHandler = new ErrorHandler(
                $this->app,
                $this->service(Logger::class),
                $this->service(ViewEngine::class)
            );

            $this->app->loadRoutes();

            $kernel = new Kernel($this->app, $this->service(Router::class), $errorHandler);

            $request = new Request('GET', '/login', [], [], [
                'REMOTE_ADDR'        => '127.0.0.1',
                'REQUEST_TIME_FLOAT' => microtime(true),
                'HTTP_USER_AGENT'    => 'vams-doctor',
            ]);

            $response = $kernel->handle($request);
            $status   = $response->status();

            $this->add(
                $status === 200 ? self::PASS : self::FAIL,
                'Sign-in page',
                $status === 200
                    ? 'rendered ' . number_format(strlen($response->content())) . ' bytes'
                    : 'answered ' . $status
            );
        } catch (Throwable $e) {
            $this->add(self::FAIL, 'Sign-in page', $e::class . ': ' . $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    // ------------------------------------------------------------------
    // Reporting
    // ------------------------------------------------------------------

    private function add(string $state, string $check, string $detail): void
    {
        $this->results[] = ['state' => $state, 'check' => $check, 'detail' => $detail];
    }

    private function report(): int
    {
        $failures = count(array_filter(
            $this->results,
            static fn (array $result): bool => $result['state'] === self::FAIL
        ));

        if ($failures === 0 && $this->hasOption('quiet-when-well')) {
            return 0;
        }

        $this->output->title('Installation check');
        $this->output->table(
            ['', 'Check', 'Detail'],
            array_map(
                fn (array $result): array => [
                    $this->badge($result['state']),
                    $result['check'],
                    $result['detail'],
                ],
                $this->results
            )
        );

        if ($failures > 0) {
            $this->output->error(sprintf('%d check(s) failed. The system will not serve correctly.', $failures));

            return 1;
        }

        $this->output->success('The installation can serve pages.');

        return 0;
    }

    private function badge(string $state): string
    {
        return match ($state) {
            self::FAIL => $this->output->colour('FAIL', 'red', 'bold'),
            self::WARN => $this->output->colour('warn', 'yellow'),
            default    => $this->output->colour(' ok ', 'green'),
        };
    }

    /**
     * The driver-level reason, which the wrapper keeps out of its own message.
     */
    private function reason(Throwable $e): string
    {
        if ($e instanceof \App\Exceptions\VamsException) {
            $driverMessage = $e->context()['driver_message'] ?? null;

            if (is_string($driverMessage) && $driverMessage !== '') {
                return $driverMessage;
            }
        }

        return $e->getMessage();
    }
}
