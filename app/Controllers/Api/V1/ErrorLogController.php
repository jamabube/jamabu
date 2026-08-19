<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\ErrorLogRepository;
use App\Services\ErrorLogService;

/**
 * Application error register.
 *
 * Errors are deduplicated by signature and counted, so a fault that happens
 * four thousand times appears once with an occurrence count rather than
 * burying everything else.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class ErrorLogController extends Controller
{
    /**
     * GET /api/v1/errors
     */
    public function index(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'last_seen_at');
        $paginator = $this->service(ErrorLogRepository::class)->paginate([
            'search'      => $request->string('search'),
            'severity'    => $request->string('severity'),
            'module'      => $request->string('module'),
            'user_id'     => $request->string('user_id'),
            'device_id'   => $request->string('device_id'),
            'assigned_to' => $request->string('assigned_to'),
            'resolved'    => $request->string('resolved'),
            'date_from'   => $request->string('date_from'),
            'date_to'     => $request->string('date_to'),
        ], $options);

        return $this->paginated('Errors retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/errors/summary
     */
    public function summary(Request $request): JsonResponse
    {
        $repository = $this->service(ErrorLogRepository::class);

        return $this->json('Error summary retrieved.', [
            'unresolved' => $repository->countUnresolved(),
            'today'      => $repository->countSince(now()->format('Y-m-d 00:00:00')),
            'recent'     => $repository->recentUnresolved(),
            'modules'    => $repository->modules(),
        ]);
    }

    /**
     * GET /api/v1/errors/{id}
     */
    public function show(Request $request): JsonResponse
    {
        $error = $this->service(ErrorLogRepository::class)->find($request->routeInt('id'));

        if ($error === null) {
            return $this->failure('NOT_FOUND', 'That error record does not exist.', 404);
        }

        return $this->json('Error retrieved.', $error);
    }

    /**
     * GET /api/v1/errors/reference/{reference}
     *
     * The reference is the code shown to the user on an error page. Being able
     * to paste it here is what turns "something went wrong" into a diagnosis.
     */
    public function byReference(Request $request): JsonResponse
    {
        $reference = (string) $request->route('reference', '');
        $error     = $this->service(ErrorLogRepository::class)->findByReference($reference);

        if ($error === null) {
            return $this->failure('NOT_FOUND', 'No error is recorded under that reference.', 404);
        }

        return $this->json('Error retrieved.', $error);
    }

    /**
     * POST /api/v1/errors/{id}/resolve
     */
    public function resolve(Request $request): JsonResponse
    {
        $errorLogId = $request->routeInt('id');

        $payload = $this->validate($request, [
            'notes' => 'required|string|min:5|max:1000',
        ], [
            'notes' => 'Resolution notes',
        ]);

        $this->service(ErrorLogService::class)->resolve($errorLogId, (int) $this->auth->id(), (string) $payload['notes']);

        return $this->json('The error was marked resolved.', ['error_log_id' => $errorLogId]);
    }

    /**
     * POST /api/v1/errors/{id}/reopen
     */
    public function reopen(Request $request): JsonResponse
    {
        $errorLogId = $request->routeInt('id');

        $this->service(ErrorLogRepository::class)->reopen($errorLogId);

        return $this->json('The error was reopened.', ['error_log_id' => $errorLogId]);
    }

    /**
     * POST /api/v1/errors/{id}/assign
     */
    public function assign(Request $request): JsonResponse
    {
        $errorLogId = $request->routeInt('id');

        $payload = $this->validate($request, [
            'user_id' => 'nullable|integer|exists:users,user_id',
        ], [
            'user_id' => 'Assignee',
        ]);

        $userId = isset($payload['user_id']) && $payload['user_id'] !== '' ? (int) $payload['user_id'] : null;

        $this->service(ErrorLogRepository::class)->assign($errorLogId, $userId);

        return $this->json(
            $userId === null ? 'The error was unassigned.' : 'The error was assigned.',
            ['error_log_id' => $errorLogId, 'user_id' => $userId]
        );
    }
}
