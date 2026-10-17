<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Modules\Calendar\CalendarIntegration;
use Modules\Calendar\CalendarSyncService;
use Modules\Calendar\NextcloudCalDavAdapter;
use Throwable;

final class NextcloudCalendarController extends Controller
{
    public function discover(Request $request): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorizeAdmin($request, $family);
        $data = $request->validate([
            'server_url' => ['required', 'url', 'max:500'],
            'username' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:500'],
        ]);

        $last = null;
        foreach ($this->endpoints($data['server_url']) as $endpoint) {
            try {
                $probe = new CalendarIntegration(['endpoint_url' => $endpoint, 'username' => $data['username'], 'password' => $data['password']]);
                $calendars = (new NextcloudCalDavAdapter($probe))->discoverCalendars();
                $request->session()->put('nextcloud_calendar.pending', $data + ['endpoint_url' => $endpoint, 'calendars' => $calendars]);
                return back()->with('status', 'nextcloud_calendars_discovered');
            } catch (Throwable $e) {
                $last = $e;
            }
        }
        return back()->withErrors(['server_url' => 'Nextcloud CalDAV discovery failed: '.mb_substr($last?->getMessage() ?? 'Unknown error', 0, 300)]);
    }

    public function select(Request $request): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorizeAdmin($request, $family);
        $pending = $request->session()->get('nextcloud_calendar.pending');
        abort_unless($pending, 422, 'Nextcloud discovery has expired.');
        $data = $request->validate(['calendar_id' => ['required', 'string']]);
        $calendar = collect($pending['calendars'])->firstWhere('id', $data['calendar_id']);
        abort_unless($calendar && ($calendar['writable'] ?? false), 422, 'Choose a writable calendar returned by Nextcloud.');

        $integration = CalendarIntegration::updateOrCreate(['family_id' => $family->id, 'provider' => 'nextcloud_caldav'], [
            'server_url' => $pending['server_url'], 'endpoint_url' => $pending['endpoint_url'],
            'principal_url' => $calendar['principal_url'], 'calendar_home_url' => $calendar['calendar_home_url'],
            'calendar_id' => $calendar['href'], 'calendar_name' => $calendar['name'],
            'username' => $pending['username'], 'password' => $pending['password'],
            'status' => 'active', 'sync_cursor' => null, 'last_error' => null,
        ]);
        $request->session()->forget('nextcloud_calendar.pending');
        try {
            app(CalendarSyncService::class)->sync($integration, true);
        } catch (Throwable) {
            // The integration remains visible with its error status so the admin can retry manually.
        }
        return to_route('settings.calendar')->with('status', 'calendar_connected');
    }

    public function sync(Request $request, CalendarSyncService $sync): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorizeAdmin($request, $family);
        $integration = CalendarIntegration::where('provider', 'nextcloud_caldav')->firstOrFail();
        $sync->sync($integration, $integration->last_synced_at === null);
        return back()->with('status', 'calendar_synced');
    }

    public function destroy(Request $request): RedirectResponse
    {
        $family = Family::findOrFail(CurrentFamily::id());
        $this->authorizeAdmin($request, $family);
        CalendarIntegration::where('provider', 'nextcloud_caldav')->first()?->delete();
        return back()->with('status', 'calendar_disconnected');
    }

    private function endpoints(string $url): array
    {
        $parts = parse_url($url);
        $origin = ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
        $path = rtrim($parts['path'] ?? '', '/');
        if (str_contains($path, '/remote.php/dav')) {
            $base = substr($path, 0, (int) strpos($path, '/remote.php/dav'));
            return [$origin.$base.'/remote.php/dav'];
        }
        $base = str_contains($path, '/apps/') ? substr($path, 0, (int) strpos($path, '/apps/')) : $path;
        return array_values(array_unique([$origin.$base.'/remote.php/dav', $origin.'/.well-known/caldav']));
    }

    private function authorizeAdmin(Request $request, Family $family): void
    {
        abort_unless($family->members()->where('users.id', $request->user()->id)->wherePivot('role', 'admin')->exists(), 403);
    }
}
