<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Demo\DemoFamily;
use App\Support\Modules\ModuleRegistry;
use App\Support\Tenancy\CurrentFamily;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Birthdays\BirthdayDate;
use Modules\Contacts\Person;
use Modules\Notes\Note;
use Modules\Projects\Project;
use Modules\Spending\Spend;
use Modules\Spending\SpendEvent;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;
use Modules\Vehicles\Vehicle;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// A real (non-demo) family with rows in several modules, including child rows without a family_id
// of their own (spend event members, shares). The reset must leave every one of them untouched.
function demoOtherFamily(): array
{
    test()->artisan('modules:migrate')->assertExitCode(0);
    $user = User::factory()->create(['email' => 'real@example.com']);
    $family = Family::factory()->create(['owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);
    $registry = app(ModuleRegistry::class);
    foreach (['tasks', 'notes', 'spending', 'contacts', 'birthdays', 'vehicles'] as $m) {
        $registry->enable($m, $family->id);
    }

    CurrentFamily::set($family->id);
    $list = TaskList::create(['name' => 'Other chores', 'created_by' => $user->id]);
    Task::create(['task_list_id' => $list->id, 'title' => 'Other family secret task', 'created_by' => $user->id]);
    Note::create(['title' => 'Other family secret note', 'body' => 'shh', 'created_by' => $user->id]);
    $person = Person::create(['name' => 'Other Person', 'created_by' => $user->id]);
    BirthdayDate::create(['person_id' => $person->id, 'month' => 1, 'day' => 1, 'birthdate' => '1990-01-01']);
    $event = SpendEvent::createFor($user, ['name' => 'Other list', 'currency' => 'EUR']);
    Spend::create(['spend_event_id' => $event->id, 'payer_id' => $user->id, 'created_by' => $user->id, 'amount' => 1234, 'currency' => 'EUR', 'base_amount' => 1234, 'category' => 'food', 'spent_at' => '2026-09-01']);
    Vehicle::create(['make' => 'Dacia', 'model' => 'Duster', 'current_mileage' => 1000, 'created_by' => $user->id]);
    CurrentFamily::forget();

    return [$family, $user];
}

// Everything the family owns, plus the non-family-scoped rows that hang off it. `exact` keeps ids and
// timestamps (untouched-ness); otherwise they are dropped so two seeder runs can be compared row-for-row.
function demoSnapshot(int $familyId, bool $exact): array
{
    $out = [];
    foreach (DemoFamily::familyScopedTables() as $table) {
        $out[$table] = DB::table($table)->where('family_id', $familyId)->get()
            ->map(fn ($r) => collect((array) $r)
                ->when(! $exact, fn ($c) => $c->except(['id', 'created_at', 'updated_at'])->reject(fn ($v, $k) => $k !== 'family_id' && str_ends_with($k, '_id')))
                ->sortKeys()->toJson())
            ->sort()->values()->all();
    }
    $events = DB::table('spending_events')->where('family_id', $familyId)->pluck('id');
    $out['spending_event_user'] = DB::table('spending_event_user')->whereIn('spend_event_id', $events)->count();
    $out['shares'] = DB::table('shares')->where('shareable_type', SpendEvent::class)->whereIn('shareable_id', $events)->count();
    $out['users'] = DB::table('family_user')->where('family_id', $familyId)->count();

    return $out;
}

function demoSeed(): Family
{
    test()->artisan('modules:migrate')->assertExitCode(0);
    test()->seed(DemoSeeder::class);

    return DemoFamily::locate();
}

// The seeder copies demo photos to the r2 disk; never let a test reach real storage.
beforeEach(fn () => Storage::fake('r2'));

it('creates the flagged demo family with five members and every module filled', function () {
    $demo = demoSeed();

    expect($demo->is_demo)->toBeTrue()
        ->and(Family::where('is_demo', true)->count())->toBe(1)
        ->and($demo->members()->count())->toBe(5)
        ->and(User::where('email', DemoSeeder::OWNER_EMAIL)->value('id'))->toBe($demo->owner_id);

    $count = fn (string $table) => DB::table($table)->where('family_id', $demo->id)->count();
    expect($count('shopping_lists'))->toBe(5)
        ->and($count('spending_spends'))->toBeGreaterThan(40)
        ->and($count('inventory_items'))->toBeGreaterThan(20)
        ->and($count('vehicles_vehicles'))->toBe(2)
        ->and($count('vehicles_maintenance_schedules'))->toBe(10)
        ->and($count('vehicles_fuel_entries'))->toBe(8)
        ->and($count('vehicles_repairs'))->toBe(2)
        ->and($count('tasks_lists'))->toBe(3)
        ->and($count('notes_notes'))->toBe(6)
        ->and($count('contacts_people'))->toBeGreaterThan(366);

    // Spending has rows in the current month (the month-to-date widget must show numbers).
    expect(DB::table('spending_spends')->where('family_id', $demo->id)->where('spent_at', '>=', today()->startOfMonth()->toDateString())->count())->toBeGreaterThan(5);
    // Every module the demo relies on is enabled.
    $enabled = DB::table('family_modules')->where(['family_id' => $demo->id, 'enabled' => true])->pluck('module_id')->all();
    foreach (['shopping', 'spending', 'inventory', 'vehicles', 'tasks', 'notes', 'contacts', 'birthdays'] as $m) {
        expect($enabled)->toContain($m);
    }
});

it('gives the demo cars and projects photos, uploaded once to fixed paths', function () {
    $demo = demoSeed();
    CurrentFamily::set($demo->id);
    $paths = [
        ...Vehicle::orderBy('id')->pluck('photo_path')->all(),
        ...Project::orderBy('id')->pluck('cover_path')->all(),
    ];
    expect($paths)->toBe(['demo/vehicle-crv.webp', 'demo/vehicle-camry.webp', 'demo/project-kitchen.webp', 'demo/project-garden.webp']);
    foreach ($paths as $p) {
        Storage::disk('r2')->assertExists([$p, $p.'.thumb.jpg']);
    }

    // A visitor deleting a demo photo gets it back on the next reset; existing files are not rewritten.
    Storage::disk('r2')->delete('demo/vehicle-crv.webp');
    $kept = Storage::disk('r2')->lastModified('demo/project-garden.webp');
    test()->artisan('demo:reset')->assertExitCode(0);
    Storage::disk('r2')->assertExists('demo/vehicle-crv.webp');
    expect(Storage::disk('r2')->lastModified('demo/project-garden.webp'))->toBe($kept);
});

it('puts at least one birthday on every calendar day, Feb 29 included', function () {
    $demo = demoSeed();

    $have = DB::table('birthdays_dates')->where('family_id', $demo->id)->get(['month', 'day'])
        ->map(fn ($r) => sprintf('%02d-%02d', $r->month, $r->day))->unique()->sort()->values()->all();
    $want = [];
    for ($d = Carbon::create(2024, 1, 1); $d->year === 2024; $d->addDay()) {
        $want[] = $d->format('m-d');
    }
    expect($have)->toBe($want)->and(count($have))->toBe(366)->and($have)->toContain('02-29');
});

it('is deterministic: re-running the seeder produces identical data with the same family and users', function () {
    $demo = demoSeed();
    $ownerId = $demo->owner_id;
    $first = demoSnapshot($demo->id, exact: false);

    test()->seed(DemoSeeder::class);

    $again = DemoFamily::locate();
    expect($again->id)->toBe($demo->id)->and($again->owner_id)->toBe($ownerId)
        ->and(Family::count())->toBe(1)
        ->and(User::count())->toBe(5)
        ->and(demoSnapshot($demo->id, exact: false))->toEqual($first);
});

it('demo:reset wipes and re-seeds only the demo family, leaving another family untouched', function () {
    [$other, $realUser] = demoOtherFamily();
    $demo = demoSeed();

    // A visitor messes with the demo: adds rows, shares something, edits a member's name.
    CurrentFamily::set($demo->id);
    $owner = User::find($demo->owner_id);
    $list = TaskList::create(['name' => 'Visitor list', 'created_by' => $owner->id]);
    Task::create(['task_list_id' => $list->id, 'title' => 'Visitor junk task', 'created_by' => $owner->id]);
    $note = Note::create(['title' => 'Visitor junk note', 'body' => '', 'created_by' => $owner->id]);
    $note->shares()->create(['user_id' => $owner->id, 'role' => 'owner']);
    Vehicle::first()->delete();
    $owner->forceFill(['name' => 'Hacked Name'])->save();
    CurrentFamily::forget();

    $otherBefore = demoSnapshot($other->id, exact: true);
    $cleanDemo = demoSnapshot($demo->id, exact: false);

    test()->artisan('demo:reset')->assertExitCode(0);

    // The other family: byte-for-byte the same, users included.
    expect(demoSnapshot($other->id, exact: true))->toEqual($otherBefore)
        ->and(User::where('email', 'real@example.com')->exists())->toBeTrue()
        ->and(Task::withoutGlobalScopes()->where('title', 'Other family secret task')->exists())->toBeTrue();

    // The demo: junk gone, canonical data back, same family id + user ids, share rows cleaned.
    expect(Task::withoutGlobalScopes()->where('title', 'Visitor junk task')->exists())->toBeFalse()
        ->and(Note::withoutGlobalScopes()->where('title', 'Visitor junk note')->exists())->toBeFalse()
        ->and(DB::table('shares')->where('shareable_type', Note::class)->count())->toBe(0)
        ->and(Vehicle::withoutGlobalScopes()->where('family_id', $demo->id)->count())->toBe(2)
        ->and(User::find($demo->owner_id)->name)->toBe('Sarah Miller')
        ->and(DemoFamily::locate()->id)->toBe($demo->id);
    expect(demoSnapshot($demo->id, exact: false))->not->toEqual($cleanDemo); // junk was in the "before"
    $cleanDemo['tasks_lists'] = array_values(array_filter($cleanDemo['tasks_lists'], fn ($r) => ! str_contains($r, 'Visitor')));
    expect(demoSnapshot($demo->id, exact: false)['tasks_lists'])->toEqual($cleanDemo['tasks_lists']);
});

it('demo:reset aborts and changes nothing when no family is flagged', function () {
    [$other] = demoOtherFamily();
    $before = demoSnapshot($other->id, exact: true);

    test()->artisan('demo:reset')->assertExitCode(1);

    expect(demoSnapshot($other->id, exact: true))->toEqual($before)
        ->and(Family::count())->toBe(1)
        ->and(Family::where('is_demo', true)->exists())->toBeFalse();
});

it('demo:reset aborts and changes nothing when more than one family is flagged', function () {
    [$other] = demoOtherFamily();
    $demo = demoSeed();
    $other->forceFill(['is_demo' => true])->save(); // corrupt state: two flagged families
    $otherBefore = demoSnapshot($other->id, exact: true);
    $demoBefore = demoSnapshot($demo->id, exact: true);

    test()->artisan('demo:reset')->assertExitCode(1);

    expect(demoSnapshot($other->id, exact: true))->toEqual($otherBefore)
        ->and(demoSnapshot($demo->id, exact: true))->toEqual($demoBefore);
    expect(fn () => DemoFamily::locate())->toThrow(RuntimeException::class);
});

it('refuses to wipe a family that is not flagged', function () {
    [$other] = demoOtherFamily();
    $before = demoSnapshot($other->id, exact: true);

    expect(fn () => DemoFamily::wipe($other))->toThrow(RuntimeException::class)
        ->and(demoSnapshot($other->id, exact: true))->toEqual($before);
});

it('signs a visitor into the demo family from /demo and shows the button on the login page', function () {
    test()->artisan('modules:migrate')->assertExitCode(0);
    get('/demo')->assertNotFound(); // nothing seeded yet
    get('/login')->assertOk()->assertInertia(fn ($p) => $p->where('canDemo', false));

    $demo = demoSeed();

    get('/login')->assertOk()->assertInertia(fn ($p) => $p->where('canDemo', true));
    umamiOn();
    get('/demo')->assertRedirect(route('dashboard'));
    test()->assertAuthenticatedAs(User::find($demo->owner_id));
    expect(session('active_family_id'))->toBe($demo->id);
    // demo_opened rides along to the dashboard, for the browser's Umami visit (Analytics::queue)
    get('/dashboard')->assertOk()->assertInertia(fn ($p) => $p->where('analyticsEvents.0.name', 'demo_opened'));
});

it('keeps the demo session scoped to the demo family', function () {
    [$other] = demoOtherFamily();
    $demo = demoSeed();
    $owner = User::find($demo->owner_id);

    actingAs($owner)->withSession(['active_family_id' => $demo->id])
        ->get('/m/notes')->assertOk()
        ->assertInertia(fn ($p) => $p->where('notes', fn ($notes) => collect($notes)->pluck('title')->doesntContain('Other family secret note') && count($notes) === 6));
    actingAs($owner)->withSession(['active_family_id' => $demo->id])
        ->get('/m/tasks')->assertOk()->assertDontSee('Other family secret task');
});

it('gives the demo owner a fixed password only when onsplan.demo_password is set, re-applied on reset', function () {
    $this->artisan('modules:migrate')->assertExitCode(0);
    $this->seed(DemoSeeder::class);
    expect(auth()->attempt(['email' => DemoSeeder::OWNER_EMAIL, 'password' => 'review-me']))->toBeFalse();

    config(['onsplan.demo_password' => 'review-me']);
    $this->seed(DemoSeeder::class);
    expect(auth()->attempt(['email' => DemoSeeder::OWNER_EMAIL, 'password' => 'review-me']))->toBeTrue()
        ->and(auth()->attempt(['email' => 'demo-david@onsplan.nl', 'password' => 'review-me']))->toBeFalse();

    // A visitor changing it via /demo only lasts until the nightly reset.
    User::where('email', DemoSeeder::OWNER_EMAIL)->first()->update(['password' => 'changed']);
    $this->seed(DemoSeeder::class);
    expect(auth()->attempt(['email' => DemoSeeder::OWNER_EMAIL, 'password' => 'review-me']))->toBeTrue();
});

it('blocks the demo owner from account takeover, invites, billing and public links, but not from the demo itself', function () {
    $family = demoSeed();
    $owner = User::where('email', DemoSeeder::OWNER_EMAIL)->first();
    $other = User::factory()->create(['email' => 'real-user@example.com']);
    $msg = 'Not available in the demo. Sign up to manage your own account and family.';

    // Takeover path: change the email (no password needed) -> "forgot password" to it. Blocked, nothing changes.
    actingAs($owner)->patch('/settings/profile', ['name' => 'Mallory', 'email' => 'attacker@example.com'])
        ->assertSessionHasErrors(['email' => $msg]);
    expect($owner->fresh()->email)->toBe(DemoSeeder::OWNER_EMAIL)->and($owner->fresh()->name)->toBe('Sarah Miller');
    actingAs($owner)->put('/settings/password', ['current_password' => 'x', 'password' => 'new-pass-123', 'password_confirmation' => 'new-pass-123'])
        ->assertSessionHasErrors('current_password');
    actingAs($owner)->delete('/settings/profile', ['password' => 'x'])->assertSessionHasErrors('password');
    expect(User::find($owner->id))->not->toBeNull();

    // A spam relay to any address, with a visitor-written family name: blocked, nothing sent.
    Mail::fake();
    actingAs($owner)->post('/family/invitations', ['email' => 'victim@example.com'])->assertSessionHasErrors('email');
    Mail::assertNothingOutgoing();
    actingAs($owner)->post('/settings/billing/checkout')->assertSessionHasErrors('demo');

    // Writing inside the modules stays open: that's what the demo is for (the hourly reset undoes it).
    CurrentFamily::set($family->id);
    $list = TaskList::first();
    actingAs($owner)->post("/m/tasks/lists/{$list->id}/tasks", ['title' => 'Demo visitor task'])->assertRedirect();
    expect(Task::where('title', 'Demo visitor task')->exists())->toBeTrue();

    // A normal account is untouched by all of this.
    actingAs($other)->patch('/settings/profile', ['name' => 'Real', 'email' => 'real-new@example.com'])->assertSessionHasNoErrors();
    expect($other->fresh()->email)->toBe('real-new@example.com');
});

it('still lets a reviewer connect Claude as the demo owner and use the MCP tools', function () {
    $family = demoSeed();
    $owner = User::where('email', DemoSeeder::OWNER_EMAIL)->first();
    $token = mcpConnect($owner, $family->id, ['tasks' => 'write'])['access_token'];

    [$err, $lists] = mcpTool($token, 'tasks_list_lists');
    expect($err)->toBeFalse()->and($lists['lists'])->not->toBeEmpty();
    [$err] = mcpTool($token, 'tasks_create_task', ['list_id' => $lists['lists'][0]['id'], 'title' => 'From Claude']);
    expect($err)->toBeFalse()->and(Task::where('title', 'From Claude')->exists())->toBeTrue();
});
