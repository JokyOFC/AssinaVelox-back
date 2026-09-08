<?php

namespace App\Http\Controllers;

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Requests\MarkNotificationsReadRequest;
use App\Http\Resources\NotificationResource;
use App\Support\CurrentOrganization;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Popover do sino (ROUTES §1.2 notifications.*): notificações do canal `database` do usuário
 * na organização corrente (data.organization_id), paginadas no shape Paginated<AppNotification>.
 */
class NotificationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $organizationId = CurrentOrganization::instance()->id();
        $perPage = min(50, max(5, (int) $request->integer('per_page', 10)));

        $notifications = $request->user()
            ->notifications()
            ->where('data->organization_id', $organizationId)
            ->latest()
            ->paginate($perPage)
            ->withQueryString();

        return NotificationResource::collection($notifications);
    }

    public function markRead(MarkNotificationsReadRequest $request): JsonResponse
    {
        $user = $request->user();
        $organizationId = CurrentOrganization::instance()->id();
        $ids = $request->ids();

        $query = $user->unreadNotifications()->where('data->organization_id', $organizationId);

        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }

        $updated = $query->update(['read_at' => now()]);

        HandleInertiaRequests::forgetCounts($organizationId, $user->getKey());

        return response()->json([
            'marked' => $updated,
            'unread' => $user->unreadNotifications()->where('data->organization_id', $organizationId)->count(),
        ]);
    }
}
