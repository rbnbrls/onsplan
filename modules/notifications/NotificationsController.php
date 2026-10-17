<?php

namespace Modules\Notifications;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

// The in-app notifications feed: each person's own log rows (already access-filtered, since a row is
// only written for someone who could see the entity). Read state uses Laravel's built-in read_at.
class NotificationsController extends Controller
{
    // Shape a stored notification for the client.
    public static function present(DatabaseNotification $n): array
    {
        $d = $n->data;

        return [
            'id' => $n->id,
            'title' => $d['title'] ?? 'onsplan',
            'body' => $d['body'] ?? '',
            'url' => $d['url'] ?? '/dashboard',
            'category' => $d['category'] ?? null,
            'categoryLabel' => $d['categoryLabel'] ?? null,
            'read' => $n->read_at !== null,
            'at' => $n->created_at->toIso8601String(),
        ];
    }

    public function index(Request $request): Response
    {
        $notes = $request->user()->notifications()->latest()->limit(100)->get();

        // Keyed `feed`, not `notifications`: the latter is a globally shared Inertia prop (the bell's
        // {unread, items}); a page prop of the same name would shadow it and break the header bell.
        return Inertia::render('notifications/index', [
            'feed' => $notes->map(fn (DatabaseNotification $n) => self::present($n))->values(),
        ]);
    }

    // Mark one as read (fired when the user taps it). 204 so the client can fire-and-forget then
    // navigate itself, same pattern as the push endpoints.
    public function read(Request $request, string $notification): HttpResponse
    {
        $request->user()->notifications()->whereKey($notification)->first()?->markAsRead();

        return response()->noContent();
    }

    public function readAll(Request $request): HttpResponse
    {
        $request->user()->unreadNotifications->markAsRead();

        return response()->noContent();
    }
}
