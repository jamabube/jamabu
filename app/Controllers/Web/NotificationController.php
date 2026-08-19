<?php

declare(strict_types=1);

namespace App\Controllers\Web;

use App\Controllers\Controller;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\NotificationService;

/**
 * Notification centre page.
 *
 * @package App\Controllers\Web
 * @version 1.0.0
 */
final class NotificationController extends Controller
{
    /**
     * GET /notifications
     */
    public function index(Request $request): Response
    {
        $userId  = (int) $this->auth->id();
        $service = $this->service(NotificationService::class);

        return $this->render('pages/notifications/index', [
            'title'      => 'Notifications',
            'unread'     => $service->unreadCount($userId),
            'byPriority' => $service->unreadByPriority($userId),
            'canDelete'  => $this->auth->can('notifications.delete'),
        ]);
    }
}
