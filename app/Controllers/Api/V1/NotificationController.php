<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Controllers\Controller;
use App\Core\Http\JsonResponse;
use App\Core\Http\Request;
use App\Repositories\NotificationRepository;
use App\Responses\ApiResponse;
use App\Services\NotificationService;

/**
 * In-application notifications.
 *
 * Every endpoint here is scoped to the signed-in user by the repository, not
 * by a filter the caller supplies: a recipient identifier taken from the
 * request would let anyone read anyone's notifications.
 *
 * @package App\Controllers\Api\V1
 * @version 1.0.0
 */
final class NotificationController extends Controller
{
    /**
     * GET /api/v1/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $options   = $this->listOptions($request, 'created_at');
        $paginator = $this->service(NotificationRepository::class)->paginateFor($this->recipient(), [
            'search'    => $request->string('search'),
            'priority'  => $request->string('priority'),
            'type_key'  => $request->string('type_key'),
            'state'     => $request->string('state'),
            'date_from' => $request->string('date_from'),
            'date_to'   => $request->string('date_to'),
        ], $options);

        return $this->paginated('Notifications retrieved.', $paginator->items(), $paginator);
    }

    /**
     * GET /api/v1/notifications/recent
     *
     * The dropdown in the header: the newest few plus the unread count.
     */
    public function recent(Request $request): JsonResponse
    {
        $recipient = $this->recipient();
        $service   = $this->service(NotificationService::class);
        $limit     = min(50, max(1, $request->integer('limit', 10)));

        return $this->json('Recent notifications retrieved.', [
            'items'        => $service->recentFor($recipient, $limit),
            'unread'       => $service->unreadCount($recipient),
            'by_priority'  => $service->unreadByPriority($recipient),
        ]);
    }

    /**
     * GET /api/v1/notifications/unread-count
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->json('Unread count retrieved.', [
            'unread' => $this->service(NotificationService::class)->unreadCount($this->recipient()),
        ]);
    }

    /**
     * POST /api/v1/notifications/{id}/read
     */
    public function markRead(Request $request): JsonResponse
    {
        $notificationId = $request->routeInt('id');

        $changed = $this->service(NotificationService::class)->markRead($notificationId, $this->recipient());

        return $this->respondToStateChange($changed, 'The notification was marked read.', $notificationId);
    }

    /**
     * POST /api/v1/notifications/{id}/unread
     */
    public function markUnread(Request $request): JsonResponse
    {
        $notificationId = $request->routeInt('id');

        $changed = $this->service(NotificationService::class)->markUnread($notificationId, $this->recipient());

        return $this->respondToStateChange($changed, 'The notification was marked unread.', $notificationId);
    }

    /**
     * POST /api/v1/notifications/read-all
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $count = $this->service(NotificationService::class)->markAllRead($this->recipient());

        return $this->json('All notifications were marked read.', ['marked' => $count]);
    }

    /**
     * POST /api/v1/notifications/{id}/archive
     */
    public function archive(Request $request): JsonResponse
    {
        $notificationId = $request->routeInt('id');

        $changed = $this->service(NotificationService::class)->archive($notificationId, $this->recipient());

        return $this->respondToStateChange($changed, 'The notification was archived.', $notificationId);
    }

    /**
     * DELETE /api/v1/notifications/{id}
     */
    public function destroy(Request $request): JsonResponse
    {
        $notificationId = $request->routeInt('id');

        if (!$this->service(NotificationService::class)->delete($notificationId, $this->recipient())) {
            return $this->failure('NOT_FOUND', 'That notification does not exist.', 404);
        }

        return ApiResponse::deleted('The notification was removed.');
    }

    /**
     * A state change that matched no row means the notification is not this
     * user's — reported as "not found" rather than "forbidden", so the endpoint
     * cannot be used to discover which identifiers exist.
     */
    private function respondToStateChange(bool $changed, string $message, int $notificationId): JsonResponse
    {
        if (!$changed) {
            return $this->failure('NOT_FOUND', 'That notification does not exist.', 404);
        }

        return $this->json($message, ['notification_id' => $notificationId]);
    }

    private function recipient(): int
    {
        return (int) $this->auth->id();
    }
}
