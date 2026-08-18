<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use Closure;

/**
 * Contract implemented by every middleware.
 *
 * A middleware either returns a response of its own (short-circuiting the
 * pipeline) or delegates to $next and optionally decorates the result.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
interface MiddlewareInterface
{
    /**
     * @param Closure(Request):Response $next
     */
    public function handle(Request $request, Closure $next): Response;
}
