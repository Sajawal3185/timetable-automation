<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

/**
 * The bell (backend/app/routers/notifications.py). Every query is limited to
 * the logged-in user: someone else's notification is "not found", never "forbidden",
 * so nobody can learn which ids exist.
 */
class NotificationController extends Controller
{
    /** Newest first. ?unread_only=true, ?limit=1..200 (default 50). */
    public function index(Request $request): AnonymousResourceCollection
    {
        $q = $request->validate(['limit' => ['sometimes', 'integer', 'min:1', 'max:200'], 'unread_only' => ['sometimes']]);
        $unreadOnly = filter_var($q['unread_only'] ?? false, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($unreadOnly === null) {
            throw ValidationException::withMessages(['unread_only' => 'Input should be a valid boolean']);
        }

        $rows = $this->mine($request)
            ->when($unreadOnly, fn ($w) => $w->where('is_read', false))
            ->orderByDesc('created_at')->orderByDesc('id')
            ->take((int) ($q['limit'] ?? 50))
            ->get();

        return NotificationResource::collection($rows);
    }

    /** Polled every few seconds by the bell, so it returns only a number. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->mine($request)->where('is_read', false)->count()]);
    }

    public function markRead(Request $request, int $notificationId): NotificationResource
    {
        $notification = $this->mine($request)->find($notificationId) ?? abort(404, 'Notification not found');
        $notification->update(['is_read' => true]);

        return new NotificationResource($notification);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        return response()->json(['updated' => $this->mine($request)->where('is_read', false)->update(['is_read' => true])]);
    }

    private function mine(Request $request)
    {
        return Notification::where('user_id', $request->user()->id);
    }
}
