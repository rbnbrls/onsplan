<?php

namespace Modules\Notifications;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

// Stores/removes a browser's Web Push subscription for the current user (one row per device/endpoint),
// saves per-category preferences, and fires a test notification. Works on any browser with the Web
// Push API (Android Chrome/Firefox, desktop, and iOS 16.4+ when installed to the Home Screen).
class PushController extends Controller
{
    public function subscribe(Request $request): Response
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string'],
            'keys.p256dh' => ['required', 'string'],
            'keys.auth' => ['required', 'string'],
        ]);

        $request->user()->updatePushSubscription($data['endpoint'], $data['keys']['p256dh'], $data['keys']['auth']);

        return response()->noContent();
    }

    public function unsubscribe(Request $request): Response
    {
        $data = $request->validate(['endpoint' => ['required', 'string']]);
        $request->user()->deletePushSubscription($data['endpoint']);

        return response()->noContent();
    }

    public function test(Request $request): Response
    {
        $request->user()->notify(new PushAlert('onsplan', 'Push notifications are on for this device 🎉'));

        return response()->noContent();
    }

    // Save which categories the user wants pushed (account-level, applies to all their devices).
    // Validated against the registered category ids, so it stays in sync as modules add categories.
    public function prefs(Request $request): Response
    {
        $request->validate(['prefs' => ['required', 'array'], 'prefs.*' => ['boolean']]);
        $prefs = collect(NotificationCategories::ids())
            ->mapWithKeys(fn ($c) => [$c => (bool) ($request->input("prefs.$c", true))])
            ->all();
        $request->user()->update(['notification_prefs' => $prefs]);

        return response()->noContent();
    }
}
