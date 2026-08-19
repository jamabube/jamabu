<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\AuthGuard;
use App\Core\Session;
use App\Core\View\ViewEngine;
use App\Services\NotificationService;
use Closure;
use Throwable;

/**
 * Makes the values every page needs available to every template.
 *
 * Without this each controller would have to remember to pass the signed-in
 * user, the sidebar, the flash messages and the CSP nonce into every render —
 * and the one that forgot would produce a page with no navigation on it.
 *
 * Runs after authentication so the user is resolved, and before the controller
 * so a template rendered from anywhere already has what it needs.
 *
 * @package App\Middleware
 * @version 1.0.0
 */
final class ShareViewDataMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly ViewEngine $view,
        private readonly AuthGuard $auth,
        private readonly Session $session
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Sections and captured output are per-request state; a long-lived
        // process serving a second request must not inherit the first one's.
        $this->view->reset();

        $this->view->shareMany([
            'currentUser'    => $this->auth->user(),
            'currentPath'    => '/' . trim($request->path(), '/'),
            'currentRoute'   => (string) ($request->attribute('route_name') ?? ''),
            'cspNonce'       => (string) ($request->attribute('csp_nonce') ?? ''),
            'flash'          => $this->session->allFlash(),
            'errors'         => $this->errors(),
            'navigation'     => $this->auth->check() ? (array) config('navigation', []) : [],
            'unreadCount'    => $this->unreadCount(),
            'appName'        => (string) config('app.name', 'VAMS'),
            'organisation'   => (string) config('app.organization', ''),
            'appVersion'     => (string) config('app.version', '1.0.0'),
            'copyright'      => (string) config('app.copyright', ''),
            'supportContact' => (string) config('app.support.administrator', ''),
            'pollInterval'   => (int) config('monitoring.live.poll_interval_seconds', 5),
            'refreshSeconds' => (int) config('monitoring.live.dashboard_refresh', 15),
            'sessionTimeout' => (int) config('session.lifetime', 1800),
            'idleWarning'    => (int) config('session.idle_warning_seconds', 120),
            'title'          => '',
        ]);

        return $next($request);
    }

    /**
     * Validation errors flashed by a failed form submission.
     *
     * @return array<string,list<string>>
     */
    private function errors(): array
    {
        /** @var array<string,list<string>> $errors */
        $errors = $this->session->getFlash('_errors', []);

        return is_array($errors) ? $errors : [];
    }

    /**
     * The badge on the notification bell.
     *
     * A failure here must not cost the user their page, so it degrades to zero
     * rather than propagating: a missing badge is a cosmetic problem, and a
     * dashboard that will not render is not.
     */
    private function unreadCount(): int
    {
        $userId = $this->auth->id();

        if ($userId === null) {
            return 0;
        }

        try {
            return app(NotificationService::class)->unreadCount($userId);
        } catch (Throwable) {
            return 0;
        }
    }
}
