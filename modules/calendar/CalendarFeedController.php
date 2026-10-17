<?php

namespace Modules\Calendar;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Modules\Birthdays\BirthdayDate;

class CalendarFeedController extends Controller
{
    /**
     * The subscribe feed. No session: the token IS the authorization (like a guest link). We set the family
     * context by hand so the family-scoped models and visibleTo() behave as for that user in the app.
     */
    public function show(string $token): Response
    {
        $feed = CalendarFeed::withoutGlobalScope('family')->where('token', $token)->first();
        abort_unless($feed && app(ModuleRegistry::class)->isEnabled('calendar', $feed->family_id), 404);

        // Leaving a family kills its feed too (mirrors McpFamilyContext): a saved .ics URL from a member
        // who was since removed must stop serving that family's events/birthdays, not keep working forever.
        $user = User::find($feed->user_id);
        abort_unless($user && $user->families()->whereKey($feed->family_id)->exists(), 404);

        CurrentFamily::set($feed->family_id);

        $events = CalendarEvent::query()->visibleTo($feed->user_id)->get();
        $birthdays = app(ModuleRegistry::class)->isEnabled('birthdays', $feed->family_id) ? BirthdayDate::with('person')->get() : [];
        $name = 'onsplan · '.Family::find($feed->family_id)?->name;

        return response(Ics::calendar($name, $events, $birthdays, $user->locale), 200, [
            'Content-Type' => 'text/calendar; charset=utf-8',
            'Content-Disposition' => 'inline; filename="onsplan.ics"',
        ]);
    }

    public function reset(Request $request): RedirectResponse
    {
        CalendarFeed::rotate($request->user()->id);

        return back();
    }
}
