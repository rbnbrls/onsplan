<?php

namespace Modules\Vehicles;

use App\Support\Calendar\CalendarSources;
use App\Support\Engagement;
use App\Support\Mcp\McpTools;
use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Trash\Trash;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Modules\Notifications\NotificationCategories;
use Modules\Shopping\ShoppingItem;
use Modules\Tasks\Task;
use Modules\Vehicles\Console\CheckVehicles;

class VehiclesServiceProvider extends ServiceProvider
{
    /** Register the daily sweep so `vehicles:check` is available to artisan + the scheduler. */
    public function register(): void
    {
        $this->commands([CheckVehicles::class]);
    }

    public function boot(Hooks $hooks): void
    {
        // Adding a Vehicle counts as using this module (paywall timing + the admin's progress bar).
        Engagement::track('vehicles', Vehicle::class);

        // Our notification category (maintenance-due pushes), so it gets its own on/off row on the
        // Notifications settings screen. Registration is idempotent (boot re-runs per request/worker).
        NotificationCategories::register('vehicles', 'Vehicles', 'car');
        $this->loadViewsFrom(__DIR__.'/resources/views', 'vehicles'); // the history PDF template

        // MCP: tools an AI assistant can use on the family's cars, gated by the `vehicles:read` /
        // `vehicles:write` scopes granted on the consent screen (see docs/mcp.md).
        // Deletes go to the 30-day trash (vehicles_list_deleted / vehicles_restore).
        McpTools::register('vehicles', 'Vehicles', [
            Mcp\ListVehicles::class, Mcp\GetVehicle::class, Mcp\VehicleHistory::class, Mcp\ExportHistory::class,
            Mcp\LogMileage::class, Mcp\LogFuel::class, Mcp\AddMaintenance::class, Mcp\UpdateMaintenance::class, Mcp\CompleteMaintenance::class,
            Mcp\AddRepair::class, Mcp\AddExpense::class, Mcp\AddDocument::class, Mcp\AttachFile::class,
            Mcp\DeleteRecord::class, Mcp\DeleteVehicle::class, Mcp\ListDeleted::class, Mcp\Restore::class,
        ]);

        // 30-day trash: deleted cars and records in Settings > Recently deleted (VehiclesTrash).
        Trash::source('vehicles', VehiclesTrash::class);
        Trash::register(Vehicle::class);
        foreach (VehiclesTrash::RECORDS as [$model]) {
            Trash::register($model);
        }

        // MAINTENANCE -> TASKS, completion half. When a task is checked off, complete the maintenance it
        // was linked to: write history, advance the schedule, recompute next due, and clear the link so the
        // schedule can re-fire. We listen on the shared hooks bus (Tasks publishes `task.saved`) rather than
        // editing the Tasks controller — exactly how important_todos and inventory extend other modules. The
        // listener fires on EVERY task save, so it self-gates hard: only a done task, in a family with this
        // module enabled, that is actually a schedule's open task, does anything.
        $hooks->listen('task.saved', function (Task $task, $request) {
            if (! $task->done || ! $this->enabledHere()) {
                return;
            }
            // whereHas: a schedule of a trashed car stays live (hidden with it) and must not complete.
            $schedule = VehicleMaintenanceSchedule::where('open_task_id', $task->id)->whereHas('vehicle')->first();
            if ($schedule) {
                MaintenanceCompletion::fromTask($schedule, $request->user());
            }

            // TYRES -> TASKS, completion half (V1.1). A seasonal "change tyres" reminder is just a nudge, so
            // checking it off only clears the link — that lets a fresh reminder fire next season (idempotency).
            VehicleTireSet::where('open_task_id', $task->id)->update(['open_task_id' => null]);
        });

        // SHOPPING -> VEHICLES, "bought part" half (V1.1). When a shopping row is marked bought, close the
        // matching vehicle part request (flip to bought, mirror its cost into Spending if it had an estimate).
        // Same shared-bus pattern Inventory uses for its own replenishment loop; self-gates to this family.
        $hooks->listen('shopping.item.bought', function (ShoppingItem $item) {
            if (! $this->enabledHere()) {
                return;
            }
            app(VehicleParts::class)->onShoppingItemBought($item);
        });

        // The daily sweep: create tasks for due/overdue maintenance and notify. Registered the same way
        // inventory:check is — each module wires its own schedule. Fully runnable by hand regardless of
        // whether the scheduler worker is live in prod (`php artisan vehicles:check`).
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule) {
            $schedule->command('vehicles:check')->dailyAt('07:00')->withoutOverlapping();
        });

        // Calendar: the next due date of each active maintenance schedule (explicit due_on, or computed from
        // last-performed + interval by MaintenanceStatus) and document expiry dates. Vehicles are family-wide
        // (no per-entity sharing), so no visibility filter.
        CalendarSources::register('vehicles', function (string $from, string $to) {
            if (! $this->enabledHere()) {
                return [];
            }
            $items = [];
            // A due schedule that already opened a task (open, in the Vehicle maintenance list) is shown by the tasks
            // source; skip it here so it isn't on the calendar twice. Eager-loaded (one query, trashed tasks
            // excluded). Only when tasks is on, since otherwise that task item doesn't render.
            $tasksOn = app(ModuleRegistry::class)->isEnabled('tasks', (int) CurrentFamily::id());
            foreach (Vehicle::with(['maintenanceSchedules'.($tasksOn ? '.openTask' : ''), 'documents'])->get() as $car) {
                $name = trim("{$car->make} {$car->model}");
                foreach ($car->maintenanceSchedules->where('active', true) as $s) {
                    if ($tasksOn && $s->openTask && ! $s->openTask->done) {
                        continue;
                    }
                    $due = $s->statusFor((int) $car->current_mileage)['nextDueDate'];
                    if ($due && $due >= $from && $due <= $to) {
                        $items[] = CalendarSources::item('vehicle_maintenance', $s->id, "{$name}: {$s->name}", $due, href: "/m/vehicles/{$car->id}", icon: 'wrench');
                    }
                }
                foreach ($car->documents as $d) {
                    $exp = $d->expires_on?->toDateString();
                    if ($exp && $exp >= $from && $exp <= $to) {
                        $items[] = CalendarSources::item('vehicle_document', $d->id, "{$name}: {$d->title}", $exp, href: "/m/vehicles/{$car->id}", icon: 'file-text');
                    }
                }
            }

            return $items;
        });

        // Full Inertia pages, gated by `module:vehicles` so they only exist for families that enabled
        // it. Vehicles are family-wide shared config (every member sees and may edit the same cars, like
        // inventory stock or the shopping aisles), so there is no per-vehicle sharing to register — the
        // BelongsToFamily scope IS the sharing boundary. owner/primary_driver are metadata references,
        // not permission gates. Maintenance / repairs / fuel / documents / notifications / dashboard
        // widget arrive in later phases; P1 is Cars + first-class Mileage only.
        // The history PDF behind a signed link, for the MCP tool (the person opens it in a browser with no
        // onsplan session). The signature authorizes it; the controller checks the module is still on.
        Route::middleware(['web', 'signed', 'throttle:20,1'])
            ->get('/vehicles/history/{vehicle}.pdf', [VehicleExportController::class, 'signed'])
            ->whereNumber('vehicle')
            ->name('vehicles.history.pdf.signed');

        Route::middleware(['web', 'auth', 'family', 'module:vehicles'])
            ->prefix('m/vehicles')
            ->name('vehicles.')
            ->group(function () {
                // My Cars + the add/edit form. `new`/`{vehicle}/edit` render the same full-page form.
                Route::get('/', [VehiclesController::class, 'index'])->name('index');
                Route::get('/new', [VehiclesController::class, 'create'])->name('create');
                Route::post('/', [VehiclesController::class, 'store'])->name('store');

                // P5 module-level surfaces. STATIC paths, so they must precede the `/{vehicle}` catch-all
                // below (else "settings"/"widget" would bind as a vehicle id). Settings reads are open to
                // members; the write authorizes admin inside the controller (like Inventory/Spending).
                Route::get('/settings', [VehiclesController::class, 'settings'])->name('settings');
                Route::patch('/settings', [VehiclesController::class, 'updateSettings'])->name('settings.update');
                // JSON feed the home-screen dashboard widget reads (exceptions/next-ups per car).
                Route::get('/widget', [VehiclesController::class, 'widget'])->name('widget');

                // V1.1: Service providers / garages — a family-scoped manage screen + provider detail. STATIC
                // one-segment path, so registered here BEFORE the `/{vehicle}` catch-all. Any member may manage.
                Route::get('/providers', [ServiceProvidersController::class, 'index'])->name('providers.index');
                Route::post('/providers', [ServiceProvidersController::class, 'store'])->name('providers.store');
                Route::get('/providers/{provider}', [ServiceProvidersController::class, 'show'])->name('providers.show');
                Route::patch('/providers/{provider}', [ServiceProvidersController::class, 'update'])->name('providers.update');
                Route::delete('/providers/{provider}', [ServiceProvidersController::class, 'destroy'])->name('providers.destroy');

                // Per-car Overview + edit + delete.
                Route::get('/{vehicle}', [VehiclesController::class, 'show'])->name('show');
                // P5: the per-car Timeline — the whole life of the car as one chronological feed.
                Route::get('/{vehicle}/timeline', [VehiclesController::class, 'timeline'])->name('timeline');
                // The History screen's "Export history" button: the whole record as an A4 PDF.
                Route::get('/{vehicle}/history.pdf', [VehicleExportController::class, 'download'])->name('history.pdf');
                // V1.1 P3: the per-car Costs & consumption analytics — all derived from earlier phases.
                Route::get('/{vehicle}/analytics', [VehicleAnalyticsController::class, 'index'])->name('analytics');
                Route::get('/{vehicle}/edit', [VehiclesController::class, 'edit'])->name('edit');
                Route::patch('/{vehicle}', [VehiclesController::class, 'update'])->name('update');
                Route::delete('/{vehicle}', [VehiclesController::class, 'destroy'])->name('destroy');

                // Mileage: the daily-use path. Takes the new absolute odometer reading (after a quick
                // preset or manual entry), logs it and updates current_mileage. Its own endpoint so the
                // common "I drove some km" action stays a single, fast round-trip separate from a full edit.
                Route::post('/{vehicle}/mileage', [VehiclesController::class, 'updateMileage'])->name('mileage');

                // P5: a vehicle-level ad-hoc expense (the Overview "Add expense" quick action) — a Spend
                // linked to the car with no maintenance/repair context.
                Route::post('/{vehicle}/expense', [VehiclesController::class, 'addExpense'])->name('expense');

                // Maintenance (P2): recurring schedules with dual time+mileage triggers, their history, and
                // "mark as completed". Nested under the vehicle — a schedule only exists in the context of
                // its car. `{schedule}` is guarded to belong to `{vehicle}` in the controller.
                Route::get('/{vehicle}/maintenance', [VehicleMaintenanceController::class, 'index'])->name('maintenance.index');
                Route::get('/{vehicle}/maintenance/new', [VehicleMaintenanceController::class, 'create'])->name('maintenance.create');
                Route::post('/{vehicle}/maintenance', [VehicleMaintenanceController::class, 'store'])->name('maintenance.store');
                Route::get('/{vehicle}/maintenance/{schedule}', [VehicleMaintenanceController::class, 'show'])->name('maintenance.show');
                Route::get('/{vehicle}/maintenance/{schedule}/edit', [VehicleMaintenanceController::class, 'edit'])->name('maintenance.edit');
                Route::patch('/{vehicle}/maintenance/{schedule}', [VehicleMaintenanceController::class, 'update'])->name('maintenance.update');
                Route::delete('/{vehicle}/maintenance/{schedule}', [VehicleMaintenanceController::class, 'destroy'])->name('maintenance.destroy');
                // Record a performance: writes history + advances the schedule's baseline (next due recomputes).
                Route::post('/{vehicle}/maintenance/{schedule}/complete', [VehicleMaintenanceController::class, 'complete'])->name('maintenance.complete');
                // P3 integrations from the event detail: create the linked Tasks task on demand (idempotent),
                // and record an ad-hoc expense into Spending linked to this car.
                Route::post('/{vehicle}/maintenance/{schedule}/task', [VehicleMaintenanceController::class, 'createTask'])->name('maintenance.task');
                Route::post('/{vehicle}/maintenance/{schedule}/expense', [VehicleMaintenanceController::class, 'addExpense'])->name('maintenance.expense');

                // Optional cover photo (R2, same StoredImage pattern as project covers / spend receipts).
                Route::post('/{vehicle}/photo', [VehiclesController::class, 'uploadPhoto'])->name('photo.upload');
                Route::get('/{vehicle}/photo', [VehiclesController::class, 'photoFile'])->name('photo.show');
                Route::delete('/{vehicle}/photo', [VehiclesController::class, 'deletePhoto'])->name('photo.delete');

                // P4: Repairs — the car's unplanned-fix history, with invoice/photo attachments (R2) and an
                // optional Spending link when a repair carries a cost.
                Route::get('/{vehicle}/repairs', [VehicleRepairsController::class, 'index'])->name('repairs.index');
                Route::post('/{vehicle}/repairs', [VehicleRepairsController::class, 'store'])->name('repairs.store');
                Route::patch('/{vehicle}/repairs/{repair}', [VehicleRepairsController::class, 'update'])->name('repairs.update');
                Route::delete('/{vehicle}/repairs/{repair}', [VehicleRepairsController::class, 'destroy'])->name('repairs.destroy');
                Route::get('/{vehicle}/repairs/{repair}/attachments/{attachment}', [VehicleRepairsController::class, 'attachmentFile'])->name('repairs.attachment');

                // P4: Documents — type + optional expiry + single file (R2). An approaching expiry can spawn
                // exactly one renewal Task (idempotent), created here on demand and by the daily sweep.
                Route::get('/{vehicle}/documents', [VehicleDocumentsController::class, 'index'])->name('documents.index');
                Route::post('/{vehicle}/documents', [VehicleDocumentsController::class, 'store'])->name('documents.store');
                Route::patch('/{vehicle}/documents/{document}', [VehicleDocumentsController::class, 'update'])->name('documents.update');
                Route::delete('/{vehicle}/documents/{document}', [VehicleDocumentsController::class, 'destroy'])->name('documents.destroy');
                Route::get('/{vehicle}/documents/{document}/file', [VehicleDocumentsController::class, 'fileShow'])->name('documents.file');
                Route::post('/{vehicle}/documents/{document}/renewal-task', [VehicleDocumentsController::class, 'createRenewalTask'])->name('documents.renewal');

                // V1.1: Fuel — a lightweight per-car fill-up log with computed consumption / cost metrics. A
                // costed fill can mirror into Spending (opt-in); recording one also advances the odometer.
                Route::get('/{vehicle}/fuel', [VehicleFuelController::class, 'index'])->name('fuel.index');
                Route::post('/{vehicle}/fuel', [VehicleFuelController::class, 'store'])->name('fuel.store');
                Route::patch('/{vehicle}/fuel/{entry}', [VehicleFuelController::class, 'update'])->name('fuel.update');
                Route::delete('/{vehicle}/fuel/{entry}', [VehicleFuelController::class, 'destroy'])->name('fuel.destroy');

                // V1.1: Tyres — the car's tyre sets with their own lifecycle. `mount` swaps which set is on
                // the car (one at a time); `remind` spawns an idempotent seasonal "change tyres" task.
                Route::get('/{vehicle}/tires', [VehicleTiresController::class, 'index'])->name('tires.index');
                Route::post('/{vehicle}/tires', [VehicleTiresController::class, 'store'])->name('tires.store');
                Route::patch('/{vehicle}/tires/{set}', [VehicleTiresController::class, 'update'])->name('tires.update');
                Route::delete('/{vehicle}/tires/{set}', [VehicleTiresController::class, 'destroy'])->name('tires.destroy');
                Route::post('/{vehicle}/tires/{set}/mount', [VehicleTiresController::class, 'mount'])->name('tires.mount');
                Route::post('/{vehicle}/tires/{set}/remind', [VehicleTiresController::class, 'remind'])->name('tires.remind');

                // V1.1: Parts -> Shopping — add a part to the family shopping list (labelled with the car),
                // reusing the shopping.item.bought hook so a purchase can mirror into the car's Spending.
                Route::post('/{vehicle}/parts', [VehiclePartsController::class, 'store'])->name('parts.store');
                Route::delete('/{vehicle}/parts/{part}', [VehiclePartsController::class, 'destroy'])->name('parts.destroy');

                // P4: Notes — reuse the Notes module; a note attached to the car ({note} is guarded via the link).
                Route::get('/{vehicle}/notes', [VehicleNotesController::class, 'index'])->name('notes.index');
                Route::post('/{vehicle}/notes', [VehicleNotesController::class, 'store'])->name('notes.store');
                Route::patch('/{vehicle}/notes/{note}', [VehicleNotesController::class, 'update'])->name('notes.update');
                Route::delete('/{vehicle}/notes/{note}', [VehicleNotesController::class, 'destroy'])->name('notes.destroy');
            });
    }

    // True only when the current family has Vehicles enabled — the gate the task.saved listener needs so it
    // stays inert for families that never enabled the module (and before install/migrate). Mirrors the
    // pattern the important_todos listeners use.
    private function enabledHere(): bool
    {
        $familyId = CurrentFamily::id();

        return $familyId && app(ModuleRegistry::class)->isEnabled('vehicles', (int) $familyId);
    }
}
