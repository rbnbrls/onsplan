<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Calendar\CalendarIntegration;
use Modules\Calendar\CalendarSyncService;

class MicrosoftCalendarController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorizeAdmin($request, $family);
        $pending = $request->session()->get('microsoft_calendar.pending');

        return Inertia::render('settings/calendar', [
            'configured' => filled(config('services.microsoft.client_id')),
            'integration' => ($integration = CalendarIntegration::where('provider', 'microsoft_graph')->first()) ? [
                'calendarName' => $integration->calendar_name,
                'email' => $integration->microsoft_email,
                'status' => $integration->status,
                'lastSyncedAt' => $integration->last_synced_at?->toIso8601String(),
                'lastError' => $integration->last_error,
            ] : null,
            'pendingCalendars' => $pending['calendars'] ?? [],
            'nextcloud' => ($nextcloud = CalendarIntegration::where('provider', 'nextcloud_caldav')->first()) ? [
                'calendarName' => $nextcloud->calendar_name, 'username' => $nextcloud->username,
                'serverUrl' => $nextcloud->server_url, 'status' => $nextcloud->status,
                'lastSyncedAt' => $nextcloud->last_synced_at?->toIso8601String(), 'lastError' => $nextcloud->last_error,
            ] : null,
            'pendingNextcloudCalendars' => $request->session()->get('nextcloud_calendar.pending.calendars', []),
        ]);
    }

    public function redirect(Request $request): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorizeAdmin($request, $family);
        abort_unless(config('services.microsoft.client_id'), 503, 'Microsoft calendar integration is not configured.');
        $state = Str::random(48);
        $request->session()->put('microsoft_calendar.oauth_state', $state);

        $query = http_build_query([
            'client_id' => config('services.microsoft.client_id'),
            'response_type' => 'code',
            'redirect_uri' => config('services.microsoft.redirect_uri') ?: url('/settings/calendar/outlook/callback'),
            'response_mode' => 'query',
            'scope' => 'offline_access User.Read Calendars.ReadWrite',
            'state' => $state,
        ]);

        return redirect()->away('https://login.microsoftonline.com/'.config('services.microsoft.tenant', 'common').'/oauth2/v2.0/authorize?'.$query);
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless($request->filled('code') && hash_equals((string) $request->session()->pull('microsoft_calendar.oauth_state'), (string) $request->state), 403);
        $token = Http::asForm()->post('https://login.microsoftonline.com/'.config('services.microsoft.tenant', 'common').'/oauth2/v2.0/token', [
            'client_id' => config('services.microsoft.client_id'), 'client_secret' => config('services.microsoft.client_secret'),
            'grant_type' => 'authorization_code', 'code' => $request->code,
            'redirect_uri' => config('services.microsoft.redirect_uri') ?: url('/settings/calendar/outlook/callback'),
            'scope' => 'offline_access User.Read Calendars.ReadWrite',
        ])->throw()->json();
        $graph = fn (string $path) => Http::withToken($token['access_token'])->acceptJson()->get('https://graph.microsoft.com/v1.0'.$path)->throw()->json();
        $me = $graph('/me');
        $calendars = collect($graph('/me/calendars')['value'] ?? [])->map(fn (array $calendar) => [
            'id' => $calendar['id'], 'name' => $calendar['name'], 'canEdit' => (bool) ($calendar['canEdit'] ?? false),
        ])->filter(fn (array $calendar) => $calendar['canEdit'])->values()->all();
        $request->session()->put('microsoft_calendar.pending', [
            'access_token' => $token['access_token'], 'refresh_token' => $token['refresh_token'] ?? null,
            'expires_in' => $token['expires_in'] ?? 3600, 'user_id' => $me['id'] ?? null,
            'email' => $me['mail'] ?? $me['userPrincipalName'] ?? null, 'calendars' => $calendars,
        ]);

        return to_route('settings.calendar');
    }

    public function select(Request $request): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorizeAdmin($request, $family);
        $pending = $request->session()->get('microsoft_calendar.pending');
        abort_unless($pending, 422, 'Microsoft authorization has expired.');
        $data = $request->validate(['calendar_id' => ['required', 'string']]);
        $calendar = collect($pending['calendars'])->firstWhere('id', $data['calendar_id']);
        abort_unless($calendar, 422, 'Choose one of the calendars returned by Microsoft.');

        $integration = CalendarIntegration::updateOrCreate(['family_id' => $family->id, 'provider' => 'microsoft_graph'], [
            'calendar_id' => $calendar['id'], 'calendar_name' => $calendar['name'],
            'access_token' => $pending['access_token'], 'refresh_token' => $pending['refresh_token'],
            'access_token_expires_at' => now()->addSeconds(max(60, (int) $pending['expires_in'] - 60)),
            'microsoft_user_id' => $pending['user_id'], 'microsoft_email' => $pending['email'],
            'status' => 'active', 'sync_cursor' => null, 'last_error' => null,
        ]);
        $this->subscribe($integration);
        $request->session()->forget('microsoft_calendar.pending');

        return to_route('settings.calendar')->with('status', 'calendar_connected');
    }

    public function sync(Request $request, CalendarSyncService $sync): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorizeAdmin($request, $family);
        $integration = CalendarIntegration::where('provider', 'microsoft_graph')->firstOrFail();
        $sync->sync($integration, $integration->last_synced_at === null);

        return back()->with('status', 'calendar_synced');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorizeAdmin($request, $family);
        CalendarIntegration::where('provider', 'microsoft_graph')->first()?->delete();

        return back()->with('status', 'calendar_disconnected');
    }

    public function webhook(Request $request): Response
    {
        if ($request->filled('validationToken')) {
            return response($request->string('validationToken')->toString(), 200, ['Content-Type' => 'text/plain']);
        }
        foreach ($request->input('value', []) as $notification) {
            $familyId = (int) str_replace('onsplan-calendar-', '', (string) ($notification['clientState'] ?? ''));
            if ($familyId > 0) {
                $integration = CalendarIntegration::where('family_id', $familyId)->where('provider', 'microsoft_graph')->where('status', 'active')->first();
                if ($integration) app(CalendarSyncService::class)->sync($integration);
            }
        }

        return response()->noContent();
    }

    private function subscribe(CalendarIntegration $integration): void
    {
        if (! config('services.microsoft.webhook_url')) return;
        $response = (new \Modules\Calendar\MicrosoftGraphClient($integration))->post('/subscriptions', [
            'changeType' => 'created,updated,deleted',
            'notificationUrl' => config('services.microsoft.webhook_url'),
            'resource' => "/me/calendars/{$integration->calendar_id}/events",
            'expirationDateTime' => now()->addHours(40)->toIso8601String(),
            'clientState' => 'onsplan-calendar-'.$integration->family_id,
        ]);
        $integration->update(['subscription_id' => $response['id'] ?? null, 'subscription_expires_at' => $response['expirationDateTime'] ?? null]);
    }

    private function authorizeAdmin(Request $request, Family $family): void
    {
        abort_unless($family->members()->where('users.id', $request->user()->id)->wherePivot('role', 'admin')->exists(), 403);
    }
}
