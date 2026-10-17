<?php

use App\Models\OAuthClient;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Laravel\Passport\AuthCode;

use function Pest\Laravel\actingAs;

// The module-requests board (onsplan.nl) signs people in through our OAuth server as a first-party
// client: no consent screen, and only for the `board:identity` scope (the `first_party` flag on the client row).
// Helpers boardClient() / boardAuthorizeQuery() live in tests/Pest.php.

it('skips consent for a board client asking only for board:identity', function () {
    $client = boardClient();
    $user = User::factory()->create();
    [$query] = boardAuthorizeQuery($client);

    $location = actingAs($user)->get("/oauth/authorize?{$query}")->assertRedirect()->headers->get('Location');

    expect($location)->toStartWith('https://onsplan.nl/api/auth/callback?')
        ->and($location)->toContain('code=')
        ->and($location)->toContain('state=xyz');
});

it('refuses a board client asking for more than board:identity, with no consent screen', function () {
    [, $users] = mcpFamily(['tasks']);
    $client = boardClient();
    [$query] = boardAuthorizeQuery($client, 'board:identity mcp:use');

    actingAs($users[0])->get("/oauth/authorize?{$query}")->assertForbidden();

    expect(session()->has('authRequest'))->toBeFalse();
});

it('refuses an array scope instead of erroring', function () {
    [, $users] = mcpFamily(['tasks']);
    $client = boardClient();
    [$query] = boardAuthorizeQuery($client);
    $query = str_replace('scope=board%3Aidentity', 'scope%5B%5D=board%3Aidentity', $query);

    actingAs($users[0])->get("/oauth/authorize?{$query}")->assertForbidden();
});

it('refuses a board client asking only for an MCP scope', function () {
    [, $users] = mcpFamily(['tasks']);
    $client = boardClient();
    [$query] = boardAuthorizeQuery($client, 'mcp:use');

    actingAs($users[0])->get("/oauth/authorize?{$query}")->assertForbidden();
});

it('does not issue tokens when a board client reaches the consent POST', function () {
    [$family, $users] = mcpFamily(['tasks']);
    $client = boardClient();
    // Park a consent request in the session while the client is still an ordinary one, then flag it.
    $client->forceFill(['first_party' => false])->save();
    [$query] = boardAuthorizeQuery($client, 'mcp:use');
    actingAs($users[0], 'web')->get("/oauth/authorize?{$query}")->assertOk();
    $authToken = session('authToken');
    $client->forceFill(['first_party' => true])->save();

    actingAs($users[0], 'web')->post('/oauth/authorize', [
        'auth_token' => $authToken, 'family_id' => $family->id, 'access' => ['tasks' => 'write'],
    ])->assertForbidden();

    // Approve creates an auth code (the token comes later at /oauth/token), so none may exist.
    expect(AuthCode::count())->toBe(0);
});

it('only treats a live, flagged client id string as a board client', function () {
    $client = boardClient();
    $revoked = boardClient();
    $revoked->forceFill(['revoked' => true])->save();

    expect(OAuthClient::isBoardClient($client->getKey()))->toBeTrue()
        ->and(OAuthClient::isBoardClient([$client->getKey()]))->toBeFalse()
        ->and(OAuthClient::isBoardClient(null))->toBeFalse()
        ->and(OAuthClient::isBoardClient(''))->toBeFalse()
        ->and(OAuthClient::isBoardClient('nope'))->toBeFalse()
        ->and(OAuthClient::isBoardClient($revoked->getKey()))->toBeFalse();
});

it('never skips consent for a client that is not a board client', function () {
    $client = boardClient();
    $client->forceFill(['first_party' => false])->save();
    $user = User::factory()->create();
    [$query] = boardAuthorizeQuery($client);

    actingAs($user)->get("/oauth/authorize?{$query}")->assertOk();
});

it('creates a board client from the command', function () {
    mcpKeys();
    $this->artisan('board:client', ['name' => 'onsplan board', 'redirect' => 'https://onsplan.nl/api/auth/callback'])
        ->expectsOutputToContain('Client ID:')
        ->expectsOutputToContain('Client secret:')
        ->assertExitCode(0);

    $client = OAuthClient::where('name', 'onsplan board')->first();
    expect($client->redirect_uris)->toBe(['https://onsplan.nl/api/auth/callback'])
        ->and($client->first_party)->toBeTrue();
});

it('refuses a non-https redirect in the command', function () {
    mcpKeys();
    $this->artisan('board:client', ['name' => 'onsplan board', 'redirect' => 'http://onsplan.nl/cb'])
        ->assertExitCode(1);

    expect(OAuthClient::count())->toBe(0);
});

it('sends unverified people through email verification, once per session', function () {
    Notification::fake();
    $client = boardClient();
    $user = User::factory()->unverified()->create();
    [$query] = boardAuthorizeQuery($client);

    actingAs($user)->get("/oauth/authorize?{$query}")->assertRedirect(route('verification.notice'));
    expect(session('url.intended'))->toContain('/oauth/authorize?');
    actingAs($user)->get("/oauth/authorize?{$query}")->assertRedirect(route('verification.notice'));

    Notification::assertSentToTimes($user, VerifyEmail::class, 1);
});

it('does not ask MCP clients for verification', function () {
    Notification::fake();
    [$family, $users] = mcpFamily(['tasks']);
    $users[0]->forceFill(['email_verified_at' => null])->save();
    [$query] = mcpAuthorizeQuery(mcpClient());

    actingAs($users[0])->get("/oauth/authorize?{$query}")->assertOk();
    Notification::assertNothingSent();
});
