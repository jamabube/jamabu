<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Http\Kernel;
use App\Core\Security\AuthGuard;
use App\Core\Session;
use App\Core\View\ViewEngine;
use App\DTO\AuthenticatedUser;
use App\Middleware\ShareViewDataMiddleware;
use App\Repositories\RoleRepository;
use Tests\Support\RequestFactory;
use Tests\TestCase;

/**
 * Verifies that the templates render and that the contracts between them and
 * the front end hold.
 *
 * A view defect does not fail a unit test — it fails when somebody opens the
 * page. These checks open them.
 *
 * @package Tests\Integration
 * @version 1.0.0
 */
final class ViewRenderingTest extends TestCase
{
    protected bool $requiresDatabase = true;

    /**
     * Every page controller, with the action that renders it.
     *
     * Index pages only: the detail pages need a record that may not exist in
     * an empty installation, and a test that depends on seed data is a test
     * that fails for the wrong reason.
     *
     * @var list<array{0:class-string,1:string,2:string}>
     */
    private const PAGES = [
        [\App\Controllers\Web\DashboardController::class,      'index',       '/'],
        [\App\Controllers\Web\MonitoringController::class,     'live',        '/monitoring/live'],
        [\App\Controllers\Web\MonitoringController::class,     'history',     '/monitoring/history'],
        [\App\Controllers\Web\MonitoringController::class,     'inside',      '/monitoring/inside'],
        [\App\Controllers\Web\MonitoringController::class,     'denials',     '/monitoring/denials'],
        [\App\Controllers\Web\RegistryController::class,       'vehicles',    '/vehicles'],
        [\App\Controllers\Web\RegistryController::class,       'drivers',     '/drivers'],
        [\App\Controllers\Web\RegistryController::class,       'owners',      '/owners'],
        [\App\Controllers\Web\VisitorController::class,        'index',       '/visitors'],
        [\App\Controllers\Web\RfidController::class,           'tags',        '/rfid/tags'],
        [\App\Controllers\Web\RfidController::class,           'cards',       '/rfid/cards'],
        [\App\Controllers\Web\FingerprintController::class,    'index',       '/fingerprints'],
        [\App\Controllers\Web\DeviceController::class,         'index',       '/devices'],
        [\App\Controllers\Web\ReportController::class,         'index',       '/reports'],
        [\App\Controllers\Web\ReportController::class,         'analytics',   '/reports/analytics'],
        [\App\Controllers\Web\NotificationController::class,   'index',       '/notifications'],
        [\App\Controllers\Web\GovernanceController::class,     'audit',       '/audit'],
        [\App\Controllers\Web\GovernanceController::class,     'security',    '/security'],
        [\App\Controllers\Web\GovernanceController::class,     'errors',      '/errors'],
        [\App\Controllers\Web\GovernanceController::class,     'api',         '/api-management'],
        [\App\Controllers\Web\GovernanceController::class,     'health',      '/health'],
        [\App\Controllers\Web\AdministrationController::class, 'users',       '/users'],
        [\App\Controllers\Web\AdministrationController::class, 'roles',       '/roles'],
        [\App\Controllers\Web\AdministrationController::class, 'permissions', '/permissions'],
        [\App\Controllers\Web\AdministrationController::class, 'departments', '/departments'],
        [\App\Controllers\Web\AdministrationController::class, 'settings',    '/settings'],
        [\App\Controllers\Web\AdministrationController::class, 'backups',     '/backups'],
        [\App\Controllers\Web\ProfileController::class,        'show',        '/profile'],
        [\App\Controllers\Web\SearchController::class,         'index',       '/search'],
        [\App\Controllers\Web\AuthController::class,           'showChangePassword', '/profile/password'],

        // The expired variant renders a heading the ordinary one does not, and
        // it is the first page an administrator sees after installing: the
        // sign-in that follows a fresh seed lands straight on it.
        [\App\Controllers\Web\AuthController::class,           'showChangePassword', '/profile/password?expired=1'],
    ];

    public function description(): string
    {
        return 'Page templates and the contracts they share with the front end';
    }

    public function setUp(): void
    {
        $this->app->loadRoutes();
    }

    public function tearDown(): void
    {
        $this->app->make(AuthGuard::class)->clear();
        $this->app->make(Session::class)->destroy();
    }

    /**
     * Sign in as an administrator without going through a password, and run
     * the middleware that supplies every template's shared data.
     */
    private function asAdministrator(string $path): \App\Core\Http\Request
    {
        $request = RequestFactory::make('GET', $path);
        $request->setAttribute('csp_nonce', 'test-nonce');

        $this->app->instance(\App\Core\Http\Request::class, $request);

        $roles = $this->app->make(RoleRepository::class);
        $role  = $roles->findBySlug('administrator');

        $this->app->make(AuthGuard::class)->setUser(
            new AuthenticatedUser(
                id: 1,
                username: 'test-administrator',
                fullName: 'Test Administrator',
                email: 'test@forestlawn.local',
                roleId: (int) $role['role_id'],
                roleName: (string) $role['role_name'],
                roleSlug: 'administrator'
            ),
            $roles->permissionKeys((int) $role['role_id'])
        );

        $this->app->make(ViewEngine::class)->reset();
        $this->app->make(ShareViewDataMiddleware::class)->handle(
            $request,
            static fn (): \App\Core\Http\Response => \App\Core\Http\Response::html('')
        );

        return $request;
    }

    public function testEveryPageRenders(): void
    {
        $failures = [];

        // A real request runs with ErrorHandler registered, which turns a
        // notice into an exception and therefore a 500. The console does not
        // register it, so without this a template reading an array key its
        // controller never supplied would emit a warning, render the rest, and
        // pass here while failing in the browser. That is exactly how a fatal
        // on the forced password-change page reached an installation.
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if ((error_reporting() & $severity) === 0) {
                return false;
            }

            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            $failures = $this->renderEveryPage();
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $failures, 'every page template renders');
    }

    /**
     * @return list<string> One entry per page that did not render cleanly.
     */
    private function renderEveryPage(): array
    {
        $failures = [];

        foreach (self::PAGES as [$class, $method, $path]) {
            try {
                $request  = $this->asAdministrator($path);
                $response = $this->app->make($class)->{$method}($request);

                if ($response->status() !== 200) {
                    $failures[] = $path . ' answered ' . $response->status();

                    continue;
                }

                // A page that renders to almost nothing has thrown inside a
                // buffered section and swallowed its own body.
                if (strlen($response->content()) < 2000) {
                    $failures[] = $path . ' rendered only ' . strlen($response->content()) . ' bytes';
                }
            } catch (\Throwable $e) {
                $failures[] = $path . ' threw ' . $e::class . ': ' . $e->getMessage();
            }
        }

        return $failures;
    }

    public function testTheShellCarriesWhatTheFrontEndNeeds(): void
    {
        $request  = $this->asAdministrator('/');
        $response = $this->app->make(\App\Controllers\Web\DashboardController::class)->index($request);
        $html     = $response->content();

        $required = [
            'id="app-bootstrap"'      => 'the JSON data island the scripts read',
            'name="csrf-token"'       => 'the token the AJAX layer sends',
            'class="sidebar"'         => 'the navigation',
            'assets/js/core.js'       => 'the first-party scripts',
            'assets/css/app.css'      => 'the first-party stylesheet',
        ];

        $missing = [];

        foreach ($required as $needle => $why) {
            if (!str_contains($html, $needle)) {
                $missing[] = $needle . ' (' . $why . ')';
            }
        }

        $this->assertSame([], $missing, 'the application shell carries its contract with the front end');
    }

    public function testTheDataIslandIsValidJsonAndCarriesTheServerOffset(): void
    {
        $request  = $this->asAdministrator('/');
        $response = $this->app->make(\App\Controllers\Web\DashboardController::class)->index($request);

        $matched = preg_match(
            '#<script type="application/json" id="app-bootstrap">(.*?)</script>#s',
            $response->content(),
            $matches
        );

        $this->assertSame(1, $matched, 'the shell emits exactly one bootstrap data island');

        /** @var array<string,mixed>|null $payload */
        $payload = json_decode((string) ($matches[1] ?? ''), true);

        $this->assertTrue(is_array($payload), 'the data island is valid JSON');

        /*
         * The offset is what lets the browser read the naive timestamps the
         * API sends. Without it a workstation in another timezone reports
         * every movement hours out — and a scan from a moment ago reads as
         * happening in the future.
         */
        $this->assertMatches(
            '/^[+-]\d{2}:\d{2}$/',
            (string) ($payload['serverOffset'] ?? ''),
            'the data island carries the server timezone offset'
        );

        $this->assertNotSame('', (string) ($payload['csrfToken'] ?? ''), 'the data island carries a CSRF token');
    }

    public function testNoPageEmitsAnInlineScriptTheContentSecurityPolicyWouldBlock(): void
    {
        $request  = $this->asAdministrator('/');
        $response = $this->app->make(\App\Controllers\Web\DashboardController::class)->index($request);

        preg_match_all('#<script([^>]*)>#i', $response->content(), $matches);

        $offenders = [];

        foreach ($matches[1] as $attributes) {
            // A script is acceptable when it loads a file, or when it is a
            // data block the browser never executes.
            if (str_contains($attributes, 'src=') || str_contains($attributes, 'application/json')) {
                continue;
            }

            $offenders[] = trim($attributes);
        }

        $this->assertSame(
            [],
            $offenders,
            'the page has no executable inline script, so the strict CSP needs no exception for it'
        );
    }

    public function testTheSignInPageRendersWithoutASession(): void
    {
        $this->app->make(AuthGuard::class)->clear();
        $this->app->make(Session::class)->destroy();

        $response = $this->app->make(Kernel::class)->handle(RequestFactory::make('GET', '/login'));

        $this->assertSame(200, $response->status(), 'the sign-in page is reachable');
        $this->assertTrue(
            str_contains($response->content(), 'name="username"'),
            'the sign-in page carries a username field'
        );

        // The form must post to the server rather than depending on a script:
        // a workstation that cannot sign in is a gate that cannot open.
        $this->assertTrue(
            str_contains($response->content(), 'method="post"'),
            'the sign-in form works without JavaScript'
        );
    }

    public function testEveryFirstPartyAssetTheShellReferencesExists(): void
    {
        $missing = [];

        foreach (array_merge(
            (array) config('assets.app.css', []),
            (array) config('assets.app.js', [])
        ) as $asset) {
            if (!is_file(base_path('public/' . (string) $asset))) {
                $missing[] = (string) $asset;
            }
        }

        $this->assertSame([], $missing, 'every declared first-party asset is present on disk');
    }
}
