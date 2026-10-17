<?php

use App\Models\Family;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;

it('sends a freshly registered user to onboarding with no family', function () {
    post('/register', [
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('onboarding.index', absolute: false));

    expect(User::where('email', 'ada@example.com')->first()->families)->toHaveCount(0);
});

it('holds a family-less user out of the app', function () {
    $user = User::factory()->create();

    actingAs($user)->get('/dashboard')->assertRedirect(route('onboarding.index'));
});

it('shows onboarding to a family-less user and the create form', function () {
    $user = User::factory()->create();

    actingAs($user)->get('/onboarding')->assertOk();
    actingAs($user)->get('/onboarding/create')->assertOk();
});

it('creates a family from onboarding with the user as admin and lands on the dashboard', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/onboarding', [
        'name' => 'The Rivera Family',
        'description' => 'Home base',
        'color' => '#2D5E3A',
        'emoji' => '🏡',
    ])->assertRedirect(route('dashboard', absolute: false));

    $family = $user->fresh()->families()->first();
    expect($family->name)->toBe('The Rivera Family')
        ->and($family->emoji)->toBe('🏡')
        ->and($family->owner_id)->toBe($user->id)
        ->and($family->pivot->role)->toBe('admin');

    // With a family now, the app is reachable.
    actingAs($user)->get('/dashboard')->assertOk();
});

it('rejects a color outside the offered palette', function () {
    $user = User::factory()->create();

    actingAs($user)->post('/onboarding', [
        'name' => 'X', 'color' => '#000000', 'emoji' => '🌿',
    ])->assertSessionHasErrors('color');
});

it('does not let an onboarded user create a second family through onboarding', function () {
    $user = User::factory()->create();
    $family = Family::create(['name' => 'First', 'owner_id' => $user->id]);
    $family->members()->attach($user, ['role' => 'admin']);

    actingAs($user)->get('/onboarding')->assertRedirect(route('dashboard'));
    actingAs($user)->post('/onboarding', [
        'name' => 'Second', 'color' => '#2D5E3A', 'emoji' => '🌿',
    ])->assertRedirect(route('dashboard'));

    expect($user->fresh()->families)->toHaveCount(1);
});

it('starts a new family with the core modules on and leaves existing families alone', function () {
    test()->artisan('modules:migrate')->assertExitCode(0);
    $registry = app(ModuleRegistry::class);
    $existing = Family::factory()->create();
    $registry->enable('tasks', $existing->id);

    $user = User::factory()->create();
    actingAs($user)->post('/onboarding', ['name' => 'New', 'color' => '#2D5E3A', 'emoji' => '🏡'])->assertRedirect();

    $family = $user->fresh()->families()->first();
    expect($registry->enabledIds($family->id))->toEqualCanonicalizing(ModuleRegistry::DEFAULT_ENABLED_MODULES)
        ->and(DB::table('family_modules')->where('family_id', $existing->id)->pluck('module_id')->all())->toBe(['tasks']);
});
