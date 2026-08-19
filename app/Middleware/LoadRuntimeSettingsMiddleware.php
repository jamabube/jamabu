<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\SecurityRuleService;
use App\Services\SettingsService;
use Closure;

/**
 * Overlays the administrator's runtime settings onto the configuration tree.
 *
 * Runs first in the global stack so that every later middleware, service and
 * template reading config() transparently sees the values an administrator has
 * set, without any of them needing to know the settings table exists.
 *
 * The configurable security thresholds are applied after the settings, and so
 * win where the two overlap: a threshold the security officer set on the
 * security-rules screen is the more specific statement of intent.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class LoadRuntimeSettingsMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly SecurityRuleService $rules
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // SettingsService falls back to the configuration defaults when the
        // table is unreachable, so a database problem degrades the system
        // rather than stopping it.
        $this->settings->applyToConfiguration();
        $this->rules->applyToConfiguration();

        return $next($request);
    }
}
