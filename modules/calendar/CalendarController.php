<?php

namespace Modules\Calendar;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Support\Calendar\CalendarSources;
use App\Support\Modules\ModuleRegistry;
use App\Support\Sharing\ShareAccess;
use App\Support\Tenancy\CurrentFamily;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Contacts\Person;
use Modules\Projects\Project;
use Modules\Tasks\Mcp\TaskData;
use Modules\Tasks\Task;
use Modules\Calendar\CalendarSyncService;

class CalendarController extends Controller
{
    public function __construct(private ShareAccess $access) {}

    public function index(Request $request): Response
    {
        $q = $request->validate([
            'view' => ['nullable', 'in:month,timeline'],
            'zoom' => ['nullable', 'in:week,month'],
            'date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $view = $q['view'] ?? 'month';
        $zoom = $q['zoom'] ?? 'month';
        $date = $q['date'] ?? now()->toDateString();
        [$from, $to] = CalendarRange::for($view, $zoom, $date);

        $familyId = (int) CurrentFamily::id();
        $family = Family::findOrFail($familyId);
        $registry = app(ModuleRegistry::class);

        return Inertia::render('calendar/index', [
            'view' => $view,
            'zoom' => $zoom,
            'date' => $date,
            'range' => ['from' => $from, 'to' => $to],
            'items' => CalendarSources::collect($from, $to, $request->user()->id),
            // Filter chips: only sources whose module is on for this family.
            'sources' => array_values(array_filter(CalendarSources::modules(), fn ($m) => $registry->isEnabled($m, $familyId))),
            'members' => $family->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            'contacts' => $this->contactOptions(),
            'projects' => $this->projectOptions($request),
            'feedUrl' => CalendarFeed::urlFor($request->user()->id), // Task 10; null until then
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $event = CalendarEvent::createFor($request->user(), CalendarEvent::validated($request->all()));
        app(CalendarSyncService::class)->pushEvent($event);

        return back();
    }

    public function show(Request $request, CalendarEvent $event): Response
    {
        abort_unless($this->access->canView($request->user(), $event), 403);
        $family = Family::findOrFail(CurrentFamily::id());
        $tasks = Task::whereIn('id', $event->taskIds())->whereNull('parent_id')->with('subtasks')->orderBy('position')->orderBy('id')->get();
        $sub = fn (Task $t) => ['id' => $t->id, 'title' => $t->title, 'note' => $t->note, 'done' => $t->done,
            'dueDate' => optional($t->due_date)->toDateString(), 'assignedTo' => $t->assigned_to];

        return Inertia::render('calendar/event', [
            'event' => $event->toPayload(),
            'canEdit' => $this->access->canEdit($request->user(), $event),
            'canDelete' => $event->created_by === $request->user()->id,
            'members' => $family->members->map(fn ($m) => ['id' => $m->id, 'name' => $m->name])->values(),
            'tasks' => $tasks->map(fn (Task $t) => $sub($t) + ['subtasks' => $t->subtasks->map($sub)->values()])->values(),
            'share' => $this->shareState($event, $family),
            // The edit sheet's people + project pickers, same lists as the index.
            'contacts' => $this->contactOptions(),
            'projects' => $this->projectOptions($request),
        ]);
    }

    public function update(Request $request, CalendarEvent $event): RedirectResponse
    {
        abort_unless($this->access->canEdit($request->user(), $event), 403);
        $event->applyUpdate($request->only([...CalendarEvent::COLUMNS, 'people', 'project_id']));
        app(CalendarSyncService::class)->pushEvent($event->fresh());

        return back();
    }

    public function destroy(Request $request, CalendarEvent $event): RedirectResponse
    {
        abort_unless($event->created_by === $request->user()->id, 403); // creator only, like notes/recipes
        app(CalendarSyncService::class)->deleteEvent($event);
        $event->delete(); // trash; its to-dos go with it (CalendarEvent::booted)

        return to_route('calendar.index');
    }

    // ---- To-dos (listless tasks-module tasks linked to the event, same model as project tasks) ----

    // Route binding runs before the family scope, so guard family_id explicitly, then the event's role.
    private function eventForTask(Request $request, Task $task): CalendarEvent
    {
        abort_unless((int) $task->family_id === (int) CurrentFamily::id(), 403);
        $event = CalendarEvent::forTask($task);
        abort_unless($event && $this->access->canEdit($request->user(), $event), 403);

        return $event;
    }

    public function storeTask(Request $request, CalendarEvent $event): RedirectResponse
    {
        abort_unless($this->access->canEdit($request->user(), $event), 403);
        $event->addTask($request->user(), $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'note' => ['nullable', 'string', 'max:255'],
            'due_date' => ['nullable', 'date'],
            // Members only (same rule as the tasks/projects tools): no cross-family assignee or notification.
            'assigned_to' => ['nullable', 'integer', Rule::in(TaskData::memberIds())],
            'parent_id' => ['nullable', 'integer'],
        ]));

        return back();
    }

    public function updateTask(Request $request, Task $task): RedirectResponse
    {
        $event = $this->eventForTask($request, $task);
        // An empty body toggles done (the task-tree's circle and the dashboard tick), like the project routes.
        $data = $request->hasAny(['title', 'note', 'due_date', 'assigned_to', 'done'])
            ? $request->validate([
                'title' => ['sometimes', 'string', 'max:255'],
                'note' => ['sometimes', 'nullable', 'string', 'max:255'],
                'due_date' => ['sometimes', 'nullable', 'date'],
                'assigned_to' => ['sometimes', 'nullable', 'integer', Rule::in(TaskData::memberIds())],
                'done' => ['sometimes', 'boolean'],
            ])
            : ['done' => ! $task->done];
        $event->updateTask($request->user(), $task, $data);

        return back();
    }

    public function destroyTask(Request $request, Task $task): RedirectResponse
    {
        $this->eventForTask($request, $task)->deleteTask($task);

        return back();
    }

    // Drag reorder + re-nest; same contract as ProjectsController::reorderTasks ({order:[{id,parent_id}]}).
    public function reorderTasks(Request $request, CalendarEvent $event): RedirectResponse
    {
        abort_unless($this->access->canEdit($request->user(), $event), 403);
        $data = $request->validate([
            'order' => ['present', 'array'],
            'order.*.id' => ['required', 'integer'],
            'order.*.parent_id' => ['nullable', 'integer'],
        ]);
        $topIds = $event->taskIds();
        $tasks = Task::where(fn ($q) => $q->whereIn('id', $topIds)->orWhereIn('parent_id', $topIds))->get()->keyBy('id');
        abort_unless(collect($data['order'])->pluck('id')->every(fn ($id) => $tasks->has($id)), 422);
        $parentIds = collect($data['order'])->pluck('parent_id')->filter()->unique();
        foreach ($data['order'] as $row) {
            if ($row['parent_id'] !== null) {
                abort_unless($tasks->has($row['parent_id']), 422);
                abort_if($parentIds->contains($row['id']), 422, 'Only one level of nesting is allowed.');
            }
        }
        $counters = [];
        foreach ($data['order'] as $row) {
            $key = $row['parent_id'] ?? 'root';
            $pos = $counters[$key] = ($counters[$key] ?? -1) + 1;
            $tasks[$row['id']]->update(['parent_id' => $row['parent_id'], 'position' => $pos]);
            $row['parent_id'] === null
                ? $event->links()->firstOrCreate(['linkable_type' => 'task', 'linkable_id' => $row['id']])
                : $event->links()->where(['linkable_type' => 'task', 'linkable_id' => $row['id']])->delete();
        }

        return back();
    }

    // People picker: the family's contacts (Person is family-scoped).
    private function contactOptions()
    {
        return Person::orderBy('name')->get(['id', 'name'])->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values();
    }

    // Project picker: viewable, non-archived projects; null hides the picker when the projects module is off.
    private function projectOptions(Request $request)
    {
        return app(ModuleRegistry::class)->isEnabled('projects', (int) CurrentFamily::id())
            ? Project::query()->visibleTo($request->user()->id)->whereNull('archived_at')->orderBy('name')->get(['id', 'name'])
                ->map(fn ($p) => ['id' => $p->id, 'name' => $p->name])->values()
            : null;
    }

    // The ShareState the core ShareSheet expects. Same shape as RecipesController::shareState.
    private function shareState(CalendarEvent $event, Family $family): array
    {
        $visibility = $event->visibility();
        $byUser = $event->shares->keyBy('user_id');

        return [
            'type' => 'calendar_event',
            'id' => $event->id,
            'visibility' => $visibility,
            'canManage' => $this->access->canManage(request()->user(), $event),
            'members' => $family->members->map(function ($m) use ($byUser, $visibility) {
                $explicit = $byUser->get($m->id)?->role;

                return ['id' => $m->id, 'name' => $m->name, 'role' => $explicit ?? ($visibility === 'family' ? 'editor' : null), 'isOwner' => $explicit === 'owner'];
            })->values(),
            'link' => null,
        ];
    }
}
