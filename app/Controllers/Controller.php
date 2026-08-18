<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Container;
use App\Core\Database\Paginator;
use App\Core\Http\JsonResponse;
use App\Core\Http\RedirectResponse;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\AuthGuard;
use App\Core\Session;
use App\Core\Validation\Validator;
use App\Core\View\ViewEngine;
use App\Exceptions\AuthorizationException;
use App\Responses\ApiResponse;

/**
 * Base controller.
 *
 * Controllers coordinate: they validate the request, delegate to a service and
 * shape the result into a response. They contain no business rules and issue
 * no SQL. Everything in this base class exists to make that division easy to
 * hold to.
 *
 * @package App\Controllers
 * @version 1.0.0
 */
abstract class Controller
{
    public function __construct(
        protected readonly Container $container,
        protected readonly ViewEngine $view,
        protected readonly AuthGuard $auth,
        protected readonly Session $session,
        protected readonly Validator $validator
    ) {
    }

    // ------------------------------------------------------------------
    // Responses
    // ------------------------------------------------------------------

    /**
     * Render an HTML page.
     *
     * @param array<string,mixed> $data
     */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->view->render($template, $data), $status);
    }

    /**
     * Redirect to a path.
     */
    protected function redirect(string $path, int $status = 302): RedirectResponse
    {
        return new RedirectResponse($path, $status, $this->session);
    }

    /**
     * Redirect to a named route.
     *
     * @param array<string,scalar> $parameters
     */
    protected function redirectToRoute(string $name, array $parameters = []): RedirectResponse
    {
        return $this->redirect(route($name, $parameters));
    }

    /**
     * Redirect back to the referring page, falling back to the dashboard.
     */
    protected function back(Request $request, string $fallback = '/'): RedirectResponse
    {
        $referer = $request->header('referer');

        // Only same-origin referers are honoured, so this cannot be used to
        // bounce a user off-site.
        if ($referer !== null && $referer !== '') {
            $host    = parse_url($referer, PHP_URL_HOST);
            $appHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);

            if ($host === null || ($appHost !== null && strcasecmp((string) $host, (string) $appHost) === 0)) {
                $path  = (string) (parse_url($referer, PHP_URL_PATH) ?: $fallback);
                $query = parse_url($referer, PHP_URL_QUERY);

                return $this->redirect($path . ($query === null ? '' : '?' . $query));
            }
        }

        return $this->redirect($fallback);
    }

    /**
     * @param array<string,mixed>|list<mixed> $data
     * @param array<string,mixed>             $meta
     */
    protected function json(string $message, array $data = [], int $status = 200, array $meta = []): JsonResponse
    {
        return ApiResponse::success($message, $data, $status, $meta);
    }

    /**
     * @param list<array<string,mixed>> $items
     */
    protected function paginated(string $message, array $items, Paginator $paginator): JsonResponse
    {
        return ApiResponse::paginated($message, $items, $paginator->toArray());
    }

    /**
     * @param array<string,mixed> $details
     */
    protected function failure(string $errorCode, string $message, int $status = 400, array $details = []): JsonResponse
    {
        return ApiResponse::error($errorCode, $message, $status, $details);
    }

    /**
     * Respond appropriately for the request type: JSON for an API/AJAX call,
     * a redirect with a flash message for a browser form post.
     */
    protected function respond(
        Request $request,
        string $message,
        string $redirectTo,
        array $data = [],
        int $status = 200
    ): Response {
        if ($request->expectsJson()) {
            return $this->json($message, $data, $status);
        }

        return $this->redirect($redirectTo)->withSuccess($message);
    }

    // ------------------------------------------------------------------
    // Validation and authorisation
    // ------------------------------------------------------------------

    /**
     * Validate the request payload.
     *
     * @param array<string,string> $rules
     * @param array<string,string> $labels
     * @param array<string,string> $messages
     *
     * @return array<string,mixed>
     *
     * @throws \App\Exceptions\ValidationException
     */
    protected function validate(Request $request, array $rules, array $labels = [], array $messages = []): array
    {
        return $this->validator->validate($request->all(), $rules, $labels, $messages);
    }

    /**
     * Enforce a permission inside a controller action.
     *
     * Route-level permissions already cover the common case; this is for the
     * finer-grained checks a single action may need (for example, editing
     * one's own profile versus another user's).
     *
     * @throws AuthorizationException
     */
    protected function authorize(string $permission): void
    {
        if ($this->auth->cannot($permission)) {
            throw AuthorizationException::forPermission($permission);
        }
    }

    /**
     * Enforce that at least one of the permissions is held.
     *
     * @param list<string> $permissions
     *
     * @throws AuthorizationException
     */
    protected function authorizeAny(array $permissions): void
    {
        if (!$this->auth->canAny($permissions)) {
            throw AuthorizationException::forPermission(implode('|', $permissions));
        }
    }

    // ------------------------------------------------------------------
    // Request helpers
    // ------------------------------------------------------------------

    /**
     * Extract the standard listing parameters (page, size, sort, search).
     *
     * @return array{page:int,per_page:int,sort:string,direction:string,search:string}
     */
    protected function listOptions(Request $request, string $defaultSort = '', string $defaultDirection = 'DESC'): array
    {
        return [
            'page'      => max(1, $request->integer('page', 1)),
            'per_page'  => Paginator::clampPerPage($request->integer('per_page', 0)),
            'sort'      => $request->string('sort', $defaultSort),
            'direction' => strtoupper($request->string('direction', $defaultDirection)) === 'ASC' ? 'ASC' : 'DESC',
            'search'    => $request->string('search'),
        ];
    }

    /**
     * Resolve a service from the container.
     *
     * @template T of object
     * @param class-string<T> $service
     *
     * @return T
     */
    protected function service(string $service): object
    {
        /** @var T $instance */
        $instance = $this->container->make($service);

        return $instance;
    }
}
