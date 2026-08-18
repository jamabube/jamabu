<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\Connection;
use App\Core\Logging\Logger;
use App\Core\Routing\Router;
use App\Core\Security\AuthGuard;
use App\Core\Security\CsrfGuard;
use App\Core\Validation\Validator;
use App\Core\View\ViewEngine;
use App\Core\Events\EventDispatcher;
use RuntimeException;

/**
 * The application container and bootstrapper.
 *
 * Owns the composition root: everything the system needs is registered here,
 * once, so that no class anywhere else has to know how its collaborators are
 * built.
 *
 * @package App\Core
 * @version 1.0.0
 */
final class Application extends Container
{
    private static ?self $instance = null;

    private string $basePath;

    private bool $booted = false;

    private function __construct(string $basePath)
    {
        $this->basePath = rtrim($basePath, '/\\');
    }

    /**
     * Create (or return) the single application instance.
     */
    public static function create(string $basePath): self
    {
        self::$instance ??= new self($basePath);

        return self::$instance;
    }

    /**
     * @throws RuntimeException When the application has not been created yet.
     */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('The application has not been bootstrapped.');
        }

        return self::$instance;
    }

    /**
     * Discard the instance. Used between test cases only.
     */
    public static function reset(): void
    {
        self::$instance = null;
        Config::flush();
    }

    /**
     * Load the environment and configuration, then register every service.
     */
    public function boot(): self
    {
        if ($this->booted) {
            return $this;
        }

        Env::load($this->basePath('.env'));
        Config::load($this->basePath('config'));

        $this->configureRuntime();
        $this->registerCoreServices();
        $this->ensureStorageDirectories();

        $this->booted = true;

        return $this;
    }

    /**
     * Apply PHP runtime settings derived from configuration.
     */
    private function configureRuntime(): void
    {
        date_default_timezone_set((string) Config::get('app.timezone', 'UTC'));
        mb_internal_encoding('UTF-8');
        setlocale(LC_ALL, 'C');

        $debug = (bool) Config::get('app.debug', false);

        // Errors are always captured by the handler; display is gated on debug
        // so that a stack trace can never reach a production browser.
        error_reporting(E_ALL);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('display_startup_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        ini_set('zend.exception_ignore_args', $debug ? '0' : '1');
    }

    /**
     * Register the framework services in the container.
     */
    private function registerCoreServices(): void
    {
        $this->instance(self::class, $this);
        $this->instance(Container::class, $this);

        $this->singleton(Logger::class, fn (): Logger => new Logger(
            (array) Config::get('logging', []),
            $this->basePath
        ));

        $this->singleton(Connection::class, function (): Connection {
            $name = (string) Config::get('database.default', 'mysql');
            /** @var array<string,mixed>|null $settings */
            $settings = Config::get('database.connections.' . $name);

            if ($settings === null) {
                throw new RuntimeException(sprintf('Database connection "%s" is not configured.', $name));
            }

            return new Connection($settings);
        });

        $this->singleton(Session::class, fn (): Session => new Session());

        $this->singleton(CsrfGuard::class, fn (Container $c): CsrfGuard => new CsrfGuard(
            $c->make(Session::class)
        ));

        $this->singleton(AuthGuard::class, fn (Container $c): AuthGuard => new AuthGuard(
            $c->make(Session::class)
        ));

        $this->singleton(Router::class, fn (): Router => new Router());

        $this->singleton(EventDispatcher::class, fn (Container $c): EventDispatcher => new EventDispatcher($c));

        $this->singleton(ViewEngine::class, fn (): ViewEngine => new ViewEngine(
            $this->basePath('app/Views')
        ));

        // The validator is transient: each use carries its own error state.
        $this->bind(Validator::class, fn (Container $c): Validator => new Validator(
            $c->make(Connection::class)
        ));
    }

    /**
     * Create the runtime directories the application writes to.
     *
     * Doing this at boot means a fresh checkout works without a manual mkdir
     * step, and a missing directory never surfaces as a mysterious log failure.
     */
    private function ensureStorageDirectories(): void
    {
        $directories = [
            'storage/logs/audit',
            'storage/logs/errors',
            'storage/logs/security',
            'storage/logs/api',
            'storage/logs/system',
            'storage/temp',
            'storage/cache',
            'storage/exports',
            'public/uploads',
        ];

        foreach ($directories as $directory) {
            $path = $this->basePath($directory);

            if (!is_dir($path)) {
                @mkdir($path, 0o750, true);
            }
        }
    }

    /**
     * Load the route definitions.
     */
    public function loadRoutes(): void
    {
        $router = $this->make(Router::class);

        foreach (['routes/api.php', 'routes/web.php'] as $file) {
            $path = $this->basePath($file);

            if (is_file($path)) {
                (static function (Router $router, string $path): void {
                    require $path;
                })($router, $path);
            }
        }
    }

    /**
     * Build an absolute filesystem path inside the project.
     */
    public function basePath(string $path = ''): string
    {
        if ($path === '') {
            return $this->basePath;
        }

        return $this->basePath . DIRECTORY_SEPARATOR . ltrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path), DIRECTORY_SEPARATOR);
    }

    /**
     * Build an absolute URL for a path in the application.
     */
    public function url(string $path = ''): string
    {
        $base = rtrim((string) Config::get('app.url', ''), '/');

        if ($path === '') {
            return $base === '' ? '/' : $base;
        }

        return $base . '/' . ltrim($path, '/');
    }

    /**
     * Build a URL for a named route.
     *
     * @param array<string,scalar> $parameters
     */
    public function route(string $name, array $parameters = []): string
    {
        return $this->make(Router::class)->url($name, $parameters);
    }

    public function environment(): string
    {
        return (string) Config::get('app.env', 'production');
    }

    public function isProduction(): bool
    {
        return $this->environment() === 'production';
    }

    public function isDebug(): bool
    {
        return (bool) Config::get('app.debug', false);
    }

    public function version(): string
    {
        return (string) Config::get('app.version', '1.0.0');
    }

    public function isBooted(): bool
    {
        return $this->booted;
    }

    /**
     * Whether the application has a usable configuration and database. Used by
     * the installer and by the health endpoint.
     */
    public function isInstalled(): bool
    {
        if ((string) Config::get('app.key', '') === '') {
            return false;
        }

        try {
            return $this->make(Connection::class)->isHealthy();
        } catch (\Throwable) {
            return false;
        }
    }
}
