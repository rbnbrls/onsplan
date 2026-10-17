<?php

use App\Models\FamilyWastePickup;
use App\Models\FamilyWasteSettings;
use Illuminate\Support\Facades\Http;
use Modules\Calendar\CalendarEvent;
use Modules\Tasks\Task;
use Modules\Tasks\TaskList;

use function Pest\Laravel\actingAs;

function wasteFamily(): array
{
    test()->artisan('modules:migrate')->assertExitCode(0);
    $admin = \App\Models\User::factory()->create();
    $member = \App\Models\User::factory()->create();
    $family = \App\Models\Family::factory()->create(['owner_id' => $admin->id]);
    $family->members()->attach($admin, ['role' => 'admin']);
    $family->members()->attach($member, ['role' => 'member']);
    session(['active_family_id' => $family->id]);
    return [$family, $admin, $member];
}

it('allows only household admins to configure waste collection', function () {
    [$family, $admin, $member] = wasteFamily();

    actingAs($member)->get('/settings/household')->assertForbidden();
    actingAs($admin)->get('/settings/household')->assertOk();
});

it('normalizes the address and materializes provider pickups into calendar and tasks', function () {
    [$family, $admin] = wasteFamily();
    Http::fake(['api.mijnafvalwijzer.nl/*' => Http::response([
        'ophaaldagen' => ['data' => [
            ['type' => 'gft', 'date' => now()->addDays(8)->toDateString()],
            ['type' => 'papier', 'date' => now()->addDays(15)->toDateString()],
        ]],
        'ophaaldagenNext' => ['data' => []],
    ])]);

    actingAs($admin)->patch('/settings/household', [
        'postal_code' => '1234 ab', 'house_number' => '12', 'house_number_suffix' => 'a', 'street_name' => '',
        'collector' => 'mijnafvalwijzer', 'selected_waste_types' => ['gft', 'papier'],
        'task_offset_days' => 1, 'task_time' => '18:00', 'sync_horizon_months' => 3,
    ])->assertRedirect();

    expect(FamilyWasteSettings::first()->postal_code)->toBe('1234AB')
        ->and(FamilyWastePickup::count())->toBe(2)
        ->and(CalendarEvent::where('source', 'waste_collection')->count())->toBe(2)
        ->and(Task::where('source', 'waste_collection')->count())->toBe(2)
        ->and(TaskList::where('name', 'Afval')->count())->toBe(1);

    // Replaying the provider response is idempotent.
    actingAs($admin)->post('/settings/household/sync')->assertRedirect();
    expect(FamilyWastePickup::count())->toBe(2)->and(CalendarEvent::where('source', 'waste_collection')->count())->toBe(2)->and(Task::where('source', 'waste_collection')->count())->toBe(2);
});

it('rejects malformed Dutch addresses before contacting the provider', function () {
    [$family, $admin] = wasteFamily();
    Http::fake();
    actingAs($admin)->patch('/settings/household', [
        'postal_code' => '1234', 'house_number' => '12', 'collector' => 'mijnafvalwijzer',
        'task_offset_days' => 1, 'task_time' => '18:00', 'sync_horizon_months' => 3,
    ])->assertSessionHasErrors('postal_code');
    Http::assertNothingSent();
});
