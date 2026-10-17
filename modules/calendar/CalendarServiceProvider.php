<?php

namespace Modules\Calendar;

use App\Support\Calendar\CalendarSources;
use App\Support\Dashboard\DashboardAgenda;
use App\Support\Dashboard\DashboardWidgets;
use App\Support\Engagement;
use App\Support\Mcp\McpTools;
use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareableRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use App\Support\Trash\Trash;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use App\Http\Controllers\Settings\MicrosoftCalendarController;
use Modules\Contacts\Person;
use Modules\Tasks\Task;

class CalendarServiceProvider extends ServiceProvider
{
    public function boot(Hooks $hooks): void
    {
        // Adding a CalendarEvent counts as using this module (paywall timing + the admin's progress bar).
        Engagement::track('calendar', CalendarEvent::class);

        Trash::source('calendar', CalendarTrash::class);
        Trash::register(CalendarEvent::class);
        // A trashed to-do keeps its event link so it can be restored onto the event; drop the link once
        // trash:purge erases the task for good (links have no FK to tasks_tasks).
        Task::forceDeleted(fn (Task $t) => CalendarEventLink::withoutGlobalScopes()->where(['linkable_type' => 'task', 'linkable_id' => $t->id])->delete());
        ShareableRegistry::register('calendar_event', CalendarEvent::class);

        // MCP: calendar:read / calendar:write scopes.
        McpTools::register('calendar', 'Calendar', [
            Mcp\ListCalendar::class, Mcp\GetEvent::class, Mcp\CreateEvent::class,
            Mcp\UpdateEvent::class, Mcp\DeleteEvent::class, Mcp\AddTodo::class,
            Mcp\UpdateTodo::class, Mcp\DeleteTodo::class, Mcp\ListDeleted::class, Mcp\Restore::class,
        ]);

        // The calendar's own events, through the same registry other modules use.
        CalendarSources::register('calendar', function (string $from, string $to, int $userId) {
            if (! self::on()) {
                return [];
            }

            // Plus the events' dated to-dos: the tasks source only covers list tasks, so these would show nowhere.
            $todos = array_map(fn (array $p) => CalendarSources::item(
                'task', $p[0]->id, $p[0]->title, $p[0]->due_date->toDateString(), href: "/m/calendar/events/{$p[1]->id}",
                icon: 'list-checks', color: $p[1]->color, done: $p[0]->done,
                people: $p[0]->assignee ? [['type' => 'user', 'id' => $p[0]->assignee->id, 'name' => $p[0]->assignee->name]] : [],
            ), CalendarEvent::datedTodos($userId, fn ($q) => $q->whereDate('due_date', '>=', $from)->whereDate('due_date', '<=', $to)));

            return [...CalendarEvent::itemsFor($userId, $from, $to), ...$todos];
        });

        // Today / This Week: occurrences overlapping today..end of week. An ongoing multi-day event appears ONCE,
        // on Today, with "day N of M", instead of on every remaining day. dueDate = max(start, today) buckets it
        // (DashboardData puts <= today on Today). No toggleUrl: events aren't "done"; the row renders without a tick.
        DashboardAgenda::register('calendar', function () {
            $userId = auth()->id();
            if (! $userId || ! self::on()) {
                return [];
            }
            $today = now()->toDateString();

            return collect(CalendarEvent::itemsFor($userId, $today, now()->endOfWeek()->toDateString()))
                ->map(function (array $i) use ($today) {
                    $days = (int) now()->parse($i['start'])->diffInDays(now()->parse($i['end'])) + 1;
                    $subtitle = $i['startTime']
                        ? "{$i['startTime']}–{$i['endTime']}"
                        : ($days > 1
                            ? strtr(Translations::get('calendar.day_of'), [':n' => (int) now()->parse($i['start'])->diffInDays(now()->parse(max($i['start'], $today))) + 1, ':total' => $days])
                            : Translations::get('calendar.all_day'));

                    return ['title' => $i['title'], 'subtitle' => $subtitle, 'href' => $i['href'], 'icon' => $i['icon'],
                        'dueDate' => max($i['start'], $today), 'kind' => 'event', 'id' => $i['id']];
                })
                // Open, dated event to-dos due by the end of the week (overdue included), like project tasks:
                // the tasks provider skips listless tasks, so they'd never surface otherwise.
                ->concat(array_map(fn (array $p) => [
                    'title' => $p[0]->title, 'subtitle' => $p[1]->title, 'href' => "/m/calendar/events/{$p[1]->id}", 'icon' => 'list-checks',
                    'dueDate' => $p[0]->due_date->toDateString(),
                    'toggleUrl' => "/m/calendar/tasks/{$p[0]->id}", // empty-body PATCH toggles done
                    'kind' => 'calendar_todo', 'id' => $p[0]->id, // get_today's widget ticks it via calendar_update_todo
                ], CalendarEvent::datedTodos($userId, fn ($q) => $q->where('done', false)->whereDate('due_date', '<=', now()->endOfWeek()->toDateString()))))
                ->values()->all();
        });

        // Now & next card: multi-day events happening now, then ones starting within 30 days (max 4).
        DashboardWidgets::register('calendar', function () {
            $userId = auth()->id();
            if (! $userId || ! self::on()) {
                return null;
            }
            $today = now()->toDateString();
            $rows = collect(CalendarEvent::itemsFor($userId, $today, now()->addDays(30)->toDateString()))
                ->filter(fn ($i) => $i['end'] > $i['start'])
                ->map(function ($i) use ($today) {
                    $now = $i['start'] <= $today;
                    $start = now()->parse($i['start']);

                    return ['id' => $i['id'], 'title' => $i['title'], 'icon' => $i['icon'], 'color' => $i['color'], 'start' => $i['start'], 'end' => $i['end'], 'href' => $i['href'],
                        'now' => $now, 'days' => (int) $start->diffInDays(now()->parse($i['end'])) + 1,
                        'dayOf' => $now ? (int) $start->diffInDays(now()->parse($today)) + 1 : null,
                        'inDays' => $now ? null : (int) now()->parse($today)->diffInDays($start)];
                })
                ->sortBy([['now', 'desc'], ['start', 'asc']])->take(4)->values()->all();

            return $rows ?: null;
        });

        // A contact is hard-deleted: drop it from every event (links have no FK).
        $hooks->listen('person.deleted', function (Person $person) {
            if (self::on()) {
                CalendarEventLink::where(['linkable_type' => 'person', 'linkable_id' => $person->id])->delete();
            }
        });

        Route::middleware(['web', 'auth', 'family', 'module:calendar'])
            ->prefix('m/calendar')
            ->name('calendar.')
            ->group(function () {
                Route::get('/', [CalendarController::class, 'index'])->name('index');
                Route::post('/events', [CalendarController::class, 'store'])->name('events.store');
                Route::get('/events/{event}', [CalendarController::class, 'show'])->name('events.show');
                Route::patch('/events/{event}', [CalendarController::class, 'update'])->name('events.update');
                Route::delete('/events/{event}', [CalendarController::class, 'destroy'])->name('events.destroy');
                Route::post('/events/{event}/tasks', [CalendarController::class, 'storeTask'])->name('tasks.store');
                Route::post('/events/{event}/tasks/reorder', [CalendarController::class, 'reorderTasks'])->name('tasks.reorder');
                Route::patch('/tasks/{task}', [CalendarController::class, 'updateTask'])->name('tasks.update');
                Route::delete('/tasks/{task}', [CalendarController::class, 'destroyTask'])->name('tasks.destroy');
                Route::post('/feed', [CalendarFeedController::class, 'reset'])->name('feed.reset');
            });

        // Two limits, not one: per-TOKEN (not guest IP) is the main guard — Google/Apple fetch every
        // subscriber's feed from shared IP pools, so an IP-keyed limit alone would 429 real subscribers as
        // usage grows. But per-token alone has no aggregate bound: a client can flood the endpoint by trying
        // a different (guessed/garbage) token on every request, each a cheap 404 lookup. The per-IP cap is
        // generous (20x the per-token one) so it never bites a shared polling pool, only a genuine flood from
        // one source. Prefixed keys so the two limiters' buckets can never collide.
        RateLimiter::for('calendar-feed', fn (Request $request) => [
            Limit::perMinute(30)->by('t:'.$request->route('token')),
            Limit::perMinute(600)->by('ip:'.$request->ip()),
        ]);

        // ICS subscribe feed: hit by Google/Apple servers with no session; the unguessable token authorizes.
        Route::middleware(['throttle:calendar-feed'])
            ->get('/calendar/feed/{token}.ics', [CalendarFeedController::class, 'show'])
            ->where('token', '[A-Za-z0-9]+')
            ->name('calendar.feed');

        Route::match(['get', 'post'], '/integrations/microsoft/calendar/webhook', [MicrosoftCalendarController::class, 'webhook'])
            ->name('calendar.microsoft.webhook');
    }

    // Self-gate for listeners/providers: providers boot for every family (rule 2 of the hooks pattern).
    private static function on(): bool
    {
        $familyId = CurrentFamily::id();

        return $familyId && app(ModuleRegistry::class)->isEnabled('calendar', (int) $familyId);
    }
}
