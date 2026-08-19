<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Core\Http\Kernel;
use App\Core\Routing\Route;
use App\Core\Routing\Router;
use App\Core\Security\AuthGuard;
use App\Core\Session;
use App\Repositories\PermissionRepository;
use Tests\Support\RequestFactory;
use Tests\TestCase;

/**
 * Verifies the route table itself.
 *
 * A route defect is invisible until somebody navigates to the page, and by
 * then it is in production. These checks catch the three kinds that actually
 * happen: a handler that no longer exists, a permission string that is not in
 * the catalogue, and a route left unprotected by omission rather than by
 * decision.
 *
 * @package Tests\Integration
 * @version 1.0.0
 */
final class RoutingTest extends TestCase
{
    protected bool $requiresDatabase = true;

    /**
     * Routes deliberately reachable without a declared permission.
     *
     * Each is listed with the reason it is here. Anything not on this list that
     * lacks a permission is a defect, and the test says so by name.
     *
     * @var array<string,string>
     */
    private const INTENTIONALLY_UNPROTECTED = [
        // Authenticated by HMAC signature, not by a user permission.
        'api.device.authenticate'            => 'device credential exchange',
        'api.device.configuration'           => 'device reads its own settings',
        'api.device.heartbeat'               => 'device liveness',
        'api.device.status'                  => 'device reads its own state',
        'api.device.error'                   => 'device reports a fault',
        'api.device.fingerprint.verify'      => 'operator sign-on at the station',
        'api.device.fingerprint.sign-out'    => 'operator sign-off at the station',
        'api.device.fingerprint.synchronise' => 'sensor reconciliation',
        'api.device.access.entry'            => 'the station records an entry',
        'api.device.access.exit'             => 'the station records an exit',
        'api.device.access.scan'             => 'the station records a movement',
        // Reachable before a permission set exists, or needed to leave.
        'api.health'                         => 'uptime probe, no credentials',
        'api.login'                          => 'no session yet',
        'api.logout'                         => 'every user may sign out',
        'api.session'                        => 'every user may read their own session',
        'api.session.extend'                 => 'every user may keep their session alive',
        'api.password.strength'              => 'scores a candidate, stores nothing',
        'login'                              => 'the sign-in page',
        'login.submit'                       => 'the sign-in form',
        'logout'                             => 'every user may sign out',
        'profile.password'                   => 'an expired password must be changeable',
        'profile.password.submit'            => 'an expired password must be changeable',
        // Filtered inside the service, per module, against the user's grants.
        'api.reference'                      => 'dropdown content the user already sees',
        'api.reference.vehicle-types'        => 'dropdown content',
        'api.reference.visitor-types'        => 'dropdown content',
        'api.search'                         => 'each provider checks its own permission',
        'api.search.quick'                   => 'each provider checks its own permission',
        'search'                             => 'each provider checks its own permission',
    ];

    private Router $router;

    public function description(): string
    {
        return 'The route table: handlers, permissions and dispatch';
    }

    public function setUp(): void
    {
        $this->app->loadRoutes();
        $this->router = $this->app->make(Router::class);

        // The dispatch checks below must run as nobody. Another test in the
        // suite may have left a signed-in guard or an open session behind, and
        // "an unauthenticated call is refused" is only a real assertion when
        // the call really is unauthenticated.
        $this->app->make(AuthGuard::class)->clear();
        $this->app->make(Session::class)->destroy();
    }

    public function testEveryRouteHasACallableHandler(): void
    {
        $broken = [];

        foreach ($this->routes() as $route) {
            $handler = $route->handler();

            if (!is_array($handler)) {
                continue;
            }

            [$class, $method] = $handler;

            if (!class_exists($class) || !method_exists($class, $method)) {
                $broken[] = sprintf('%s %s → %s@%s', $route->method(), $route->uri(), $class, $method);
            }
        }

        $this->assertSame([], $broken, 'every route points at a controller action that exists');
    }

    public function testEveryDeclaredPermissionExistsInTheCatalogue(): void
    {
        $known   = $this->app->make(PermissionRepository::class)->allKeys();
        $unknown = [];

        foreach ($this->routes() as $route) {
            $permission = $route->getPermission();

            // "*" is unrestricted access, held by the administrator role rather
            // than granted as an individual permission row.
            if ($permission === null || $permission === '*') {
                continue;
            }

            if (!in_array($permission, $known, true)) {
                $unknown[] = sprintf('%s (%s %s)', $permission, $route->method(), $route->uri());
            }
        }

        $this->assertSame([], $unknown, 'every route permission is a permission the system actually grants');
    }

    public function testUnprotectedRoutesAreOnlyTheIntendedOnes(): void
    {
        $unexpected = [];

        foreach ($this->routes() as $route) {
            if ($route->getPermission() !== null) {
                continue;
            }

            $name = (string) $route->getName();

            if (!isset(self::INTENTIONALLY_UNPROTECTED[$name])) {
                $unexpected[] = sprintf('%s %s (%s)', $route->method(), $route->uri(), $name === '' ? 'unnamed' : $name);
            }
        }

        $this->assertSame([], $unexpected, 'no route is left without a permission by accident');
    }

    public function testEveryRouteIsNamed(): void
    {
        $unnamed = [];

        foreach ($this->routes() as $route) {
            if ($route->getName() === null) {
                $unnamed[] = $route->method() . ' ' . $route->uri();
            }
        }

        // An unnamed route cannot be linked to with route(), so a template
        // referring to it would have to hardcode the path.
        $this->assertSame([], $unnamed, 'every route carries a name');
    }

    public function testEveryNavigationEntryPointsAtARealRoute(): void
    {
        $missing = [];

        foreach ($this->navigationRoutes((array) config('navigation', [])) as $name) {
            if (!$this->router->hasName($name)) {
                $missing[] = $name;
            }
        }

        $this->assertSame([], $missing, 'every sidebar entry has a route behind it');
    }

    public function testTheLivenessProbeAnswersWithoutCredentials(): void
    {
        $response = $this->app->make(Kernel::class)
            ->handle(RequestFactory::make('GET', '/api/v1/health'));

        $this->assertSame(200, $response->status(), 'the liveness probe answers 200');
        $this->assertTrue(
            str_contains($response->content(), '"database"'),
            'the liveness payload reports the database state'
        );
    }

    public function testAnUnauthenticatedApiCallIsRefused(): void
    {
        $response = $this->app->make(Kernel::class)
            ->handle(RequestFactory::make('GET', '/api/v1/vehicles', [], ['Accept' => 'application/json']));

        $this->assertSame(401, $response->status(), 'an API call without a session is refused');
    }

    public function testAnUnknownPathAnswersNotFound(): void
    {
        $response = $this->app->make(Kernel::class)
            ->handle(RequestFactory::make('GET', '/api/v1/there-is-no-such-endpoint', [], ['Accept' => 'application/json']));

        $this->assertSame(404, $response->status(), 'an unknown endpoint answers 404');
    }

    public function testAKnownPathUnderTheWrongVerbAnswersMethodNotAllowed(): void
    {
        $response = $this->app->make(Kernel::class)
            ->handle(RequestFactory::make('DELETE', '/api/v1/health', [], ['Accept' => 'application/json']));

        $this->assertSame(405, $response->status(), 'the wrong verb is distinguished from a missing resource');
    }

    /**
     * @return list<Route>
     */
    private function routes(): array
    {
        return array_values(array_filter(
            $this->router->all(),
            static fn (mixed $route): bool => $route instanceof Route
        ));
    }

    /**
     * Flatten the navigation tree into the route names it references.
     *
     * @param array<int,array<string,mixed>> $items
     *
     * @return list<string>
     */
    private function navigationRoutes(array $items): array
    {
        $names = [];

        foreach ($items as $item) {
            if (isset($item['route']) && is_string($item['route'])) {
                $names[] = $item['route'];
            }

            if (isset($item['children']) && is_array($item['children'])) {
                foreach ($this->navigationRoutes($item['children']) as $child) {
                    $names[] = $child;
                }
            }
        }

        return $names;
    }
}
