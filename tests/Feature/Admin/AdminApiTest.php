<?php

use App\Models\Family;
use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;

// admin.onsplan.nl's read-only API. Every call: a token with `admin`, from the platform-admin client,
// for a user who is a platform admin right now.

function adminApiToken(?User $user = null): string
{
    $user ??= User::factory()->create(['name' => 'Bogdan', 'email' => 'boss@example.com', 'is_platform_admin' => true]);
    $token = boardToken($user, adminClient(), OAuthClient::ADMIN_SCOPE)['access_token'];
    auth()->forgetGuards();

    return $token;
}

it('answers me', function () {
    $token = adminApiToken();
    $this->withToken($token)->getJson('/api/admin/me')->assertOk()->assertJson([
        'name' => 'Bogdan', 'email' => 'boss@example.com', 'email_verified' => true, 'is_platform_admin' => true, 'families' => [],
    ]);
});

it('403s a board token, an MCP token, a non-admin and a revoked admin', function () {
    $this->withToken(boardToken(User::factory()->create(), boardClient())['access_token'])->getJson('/api/admin/me')->assertForbidden();
    auth()->forgetGuards();

    [$family, $users] = mcpFamily(['tasks']);
    $this->withToken(mcpConnect($users[0], $family->id, ['tasks' => 'read'])['access_token'])->getJson('/api/admin/me')->assertForbidden();
    auth()->forgetGuards();

    $user = User::factory()->create(['is_platform_admin' => true]);
    $token = adminApiToken($user);
    $user->forceFill(['is_platform_admin' => false])->save();
    $this->withToken($token)->getJson('/api/admin/users')->assertForbidden()->assertExactJson(['error' => 'forbidden']);
});

it('needs a token', function () {
    mcpKeys();
    $this->getJson('/api/admin/stats')->assertUnauthorized();
});

it('counts users, verified users, families and sign-ups, without the demo', function () {
    $token = adminApiToken();                                   // 1 verified admin, created now
    User::factory()->unverified()->create(['created_at' => now()->subDays(10)]);
    User::factory()->create(['created_at' => now()->subDays(40)]);
    $demo = Family::factory()->create();
    $demo->forceFill(['is_demo' => true])->save();              // its owner is a demo member
    $demo->members()->attach($demo->owner_id, ['role' => 'admin']);
    Family::factory()->create()->members()->attach(User::first(), ['role' => 'admin']); // real family (+ its owner user)

    $this->withToken($token)->getJson('/api/admin/stats')->assertOk()->assertExactJson([
        'users' => 4, 'verifiedUsers' => 3, 'families' => 1, 'signups7d' => 2, 'signups30d' => 3,
        'payingFamilies' => 0, 'pastDueFamilies' => 0, 'lifetimeFamilies' => 0,
    ]);
});

it('counts paying and past-due families, never the demo', function () {
    $token = adminApiToken();
    foreach (['active', 'trialing', 'past_due', 'canceled', null] as $status) {
        Family::factory()->create()->forceFill(['subscription_status' => $status])->save();
    }
    Family::factory()->create()->forceFill(['is_demo' => true, 'subscription_status' => 'active'])->save();

    $this->withToken($token)->getJson('/api/admin/stats')->assertOk()->assertJson(['payingFamilies' => 3, 'pastDueFamilies' => 1]);
});

it('lists users newest first with families, last activity and a literal search', function () {
    $token = adminApiToken();
    $ana = User::factory()->create(['name' => 'Ana 100%', 'email' => 'ana@example.com', 'created_at' => now()->subDay()]);
    User::factory()->create(['name' => 'Bob', 'email' => 'bob_x@example.com', 'created_at' => now()->subDays(2)]);
    Family::factory()->create(['owner_id' => $ana->id])->members()->attach($ana, ['role' => 'admin']);
    DB::table('sessions')->insert(['id' => 's1', 'user_id' => $ana->id, 'payload' => '', 'last_activity' => now()->subHour()->timestamp]);

    $all = $this->withToken($token)->getJson('/api/admin/users')->assertOk()->json();
    expect(collect($all['items'])->pluck('name')->all())->toBe(['Bogdan', 'Ana 100%', 'Bob'])
        ->and($all['hasMore'])->toBeFalse()
        ->and($all['items'][1])->toMatchArray(['familyCount' => 1, 'verified' => true])
        ->and($all['items'][1]['lastActiveAt'])->toBeString()
        ->and($all['items'][2]['lastActiveAt'])->toBeNull()
        ->and($all['items'][0])->not->toHaveKeys(['password', 'remember_token']);

    $q = fn (string $s) => collect($this->withToken($token)->getJson('/api/admin/users?q='.urlencode($s))->json('items'))->pluck('name')->all();
    expect($q('%'))->toBe(['Ana 100%'])
        ->and($q('_'))->toBe(['Bob'])
        ->and($q("'"))->toBe([])
        ->and($q('ANA@'))->toBe(['Ana 100%']);
});

it('shows one user with families and roles', function () {
    $token = adminApiToken();
    $ana = User::factory()->create(['name' => 'Ana']);
    $family = Family::factory()->create(['name' => 'Alves', 'owner_id' => $ana->id]);
    $family->members()->attach($ana, ['role' => 'admin']);

    $this->withToken($token)->getJson("/api/admin/users/{$ana->id}")->assertOk()->assertJson([
        'id' => $ana->id, 'name' => 'Ana', 'isPlatformAdmin' => false, 'families' => [['id' => $family->id, 'name' => 'Alves', 'role' => 'admin']],
    ]);
    $this->withToken($token)->getJson('/api/admin/users/999999')->assertNotFound()->assertExactJson(['error' => 'not_found']);
});

it('shows a user\'s analytics id and first touch on the detail only', function () {
    $token = adminApiToken();
    $ana = User::factory()->create(['name' => 'Ana']);
    $touch = ['source' => 'reddit', 'medium' => 'social', 'campaign' => null, 'referrer' => null, 'landing_path' => '/register', 'at' => '2026-10-10T10:00:00Z'];
    $ana->forceFill(['first_touch' => $touch])->save();

    $this->withToken($token)->getJson("/api/admin/users/{$ana->id}")->assertOk()
        ->assertJson(['analyticsId' => $ana->analytics_id, 'firstTouch' => $touch]);
    expect($this->withToken($token)->getJson('/api/admin/users')->json('items.0'))->not->toHaveKeys(['analyticsId', 'firstTouch']);
});

it('lists and shows families with owner, members and the demo flag', function () {
    $token = adminApiToken();
    $owner = User::factory()->create(['name' => 'Ana']);
    $family = Family::factory()->create(['name' => 'Alves', 'owner_id' => $owner->id]);
    $family->forceFill(['subscription_status' => 'active', 'stripe_customer_id' => 'cus_123'])->save();
    $family->members()->attach($owner, ['role' => 'admin']);
    $family->members()->attach(User::factory()->create(['name' => 'Rui']), ['role' => 'member']);

    $list = $this->withToken($token)->getJson('/api/admin/families?q=alv')->assertOk()->json();
    expect($list['items'])->toHaveCount(1)
        ->and($list['items'][0])->toMatchArray(['id' => $family->id, 'name' => 'Alves', 'ownerName' => 'Ana', 'memberCount' => 2, 'isDemo' => false,
            'subscriptionStatus' => 'active', 'stripeCustomerId' => 'cus_123']);

    $detail = $this->withToken($token)->getJson("/api/admin/families/{$family->id}")->assertOk()->json();
    expect(collect($detail['members'])->pluck('role', 'name')->all())->toBe(['Ana' => 'admin', 'Rui' => 'member']);
    $this->withToken($token)->getJson('/api/admin/families/999999')->assertNotFound();
});

it('pages by 50', function () {
    $token = adminApiToken();
    User::factory()->count(50)->create(['created_at' => now()->subDay()]);
    $p1 = $this->withToken($token)->getJson('/api/admin/users')->json();
    $p2 = $this->withToken($token)->getJson('/api/admin/users?page=2')->json();
    expect([count($p1['items']), $p1['hasMore'], count($p2['items']), $p2['hasMore']])->toBe([50, true, 1, false]);
});
