<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Core\Console\Command;
use App\Core\Routing\Route;
use App\Core\Routing\Router;

/**
 * Print the route table.
 *
 * Useful on its own, and useful as a check: loading this command exercises
 * both route files, so a duplicated route name or a controller class that does
 * not exist is reported here rather than on the first request after a deploy.
 *
 * @package App\Console\Commands
 * @version 1.0.0
 */
final class RouteListCommand extends Command
{
    protected string $name = 'route:list';
    protected string $description = 'List every registered route with its permission and rate-limit bucket.';
    protected string $usage = 'php bin/console route:list [--filter=vehicles] [--method=POST] [--unprotected]';

    public function handle(): int
    {
        $this->app->loadRoutes();

        /** @var Router $router */
        $router = $this->app->make(Router::class);

        $filter       = (string) ($this->option('filter', '') ?: '');
        $method       = strtoupper((string) ($this->option('method', '') ?: ''));
        $unprotected  = $this->hasOption('unprotected');

        $rows    = [];
        $counted = 0;

        foreach ($router->all() as $route) {
            if (!$route instanceof Route) {
                continue;
            }

            $counted++;

            if ($method !== '' && $route->method() !== $method) {
                continue;
            }

            if ($filter !== '' && !str_contains($route->uri(), $filter) && !str_contains((string) $route->getName(), $filter)) {
                continue;
            }

            if ($unprotected && $route->getPermission() !== null) {
                continue;
            }

            $rows[] = [
                $route->method(),
                $route->uri(),
                $route->getName() ?? '—',
                $route->getPermission() ?? '—',
                $route->getRateLimitBucket() ?? 'default',
                $this->describeHandler($route),
            ];
        }

        if ($rows === []) {
            $this->output->warning('No route matched the given filters.');

            return 0;
        }

        $this->output->table(
            ['Method', 'URI', 'Name', 'Permission', 'Throttle', 'Handler'],
            $rows
        );

        $this->output->line();
        $this->output->info(sprintf('%d of %d route(s) shown.', count($rows), $counted));

        return 0;
    }

    /**
     * A short, readable name for whatever handles the route.
     */
    private function describeHandler(Route $route): string
    {
        $handler = $route->handler();

        if (!is_array($handler) || count($handler) !== 2) {
            return 'Closure';
        }

        [$class, $method] = $handler;

        $short = substr(strrchr((string) $class, '\\') ?: (string) $class, 1);

        return $short . '@' . (string) $method;
    }
}
