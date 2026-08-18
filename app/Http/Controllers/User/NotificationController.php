<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Http\Requests\User\NotificationIndexRequest;
use App\Models\InAppNotification;
use App\Services\NotificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(
        protected NotificationService $notifications
    ) {}

    /**
     * List in-app notifications
     *
     * @authenticated
     *
     * @group Notifications
     */
    public function index(NotificationIndexRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $query = $request->user()->inAppNotifications();

        if (isset($validated['unread'])) {
            $validated['unread']
                ? $query->whereNull('read_at')
                : $query->whereNotNull('read_at');
        }
        if (isset($validated['type'])) {
            $query->where('type', $validated['type']);
        }

        $notifications = $query->paginate($validated['per_page'] ?? 25);

        return $this->successResponse(
            data: $notifications->getCollection()->map(fn (InAppNotification $notification): array => $this->present($notification)),
            message: 'Notifications retrieved successfully',
            meta: [
                'current_page' => $notifications->currentPage(),
                'last_page' => $notifications->lastPage(),
                'per_page' => $notifications->perPage(),
                'total' => $notifications->total(),
                'unread_count' => $this->notifications->unreadCount($request->user()),
            ]
        );
    }

    /**
     * Get unread notification count
     *
     * @authenticated
     *
     * @group Notifications
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return $this->successResponse(
            data: ['unread_count' => $this->notifications->unreadCount($request->user())],
            message: 'Unread notification count retrieved successfully'
        );
    }

    /**
     * Mark a notification as read
     *
     * @authenticated
     *
     * @group Notifications
     *
     * @urlParam notificationId string required Notification ULID.
     */
    public function markRead(Request $request, string $notificationId): JsonResponse
    {
        if (! $this->notifications->markRead($request->user(), $notificationId)) {
            return $this->errorResponse('Notification not found.', statusCode: 404);
        }

        return $this->successResponse(message: 'Notification marked as read');
    }

    /**
     * Mark all notifications as read
     *
     * @authenticated
     *
     * @group Notifications
     */
    public function markAllRead(Request $request): JsonResponse
    {
        return $this->successResponse(
            data: ['marked_count' => $this->notifications->markAllRead($request->user())],
            message: 'Notifications marked as read'
        );
    }

    private function present(InAppNotification $notification): array
    {
        return [
            'id' => $notification->id,
            'type' => $notification->type->value,
            'title' => $notification->title,
            'message' => $notification->message,
            'data' => $notification->data,
            'read_at' => $notification->read_at,
            'created_at' => $notification->created_at,
        ];
    }
}
