<?php

use App\Models\OAuthClient;
use App\Models\User;
use Laravel\Passport\Token;

use function Pest\Laravel\actingAs;

// admin.onsplan.nl signs in as a first-party client flagged platform_admin, with exactly
// `board:identity admin`, and only platform admins get a code. Helpers in tests/Pest.php.

function admin(): User
{
    return User::factory()->create(['is_platform_admin' => true]);
}

it('lets a platform admin in with no consent screen and an admin token', function () {
    $client = adminClient();
    $tokens = boardToken(admin(), $client, OAuthClient::ADMIN_SCOPE);

    expect(Token::query()->latest('created_at')->first()->scopes)->toEqualCanonicalizing(['board:identity', 'admin'])
        ->and($tokens)->toHaveKeys(['access_token', 'refresh_token', 'expires_in']);
});

it('refuses a non-admin at authorize, no code', function () {
    $client = adminClient();
    [$query] = boardAuthorizeQuery($client, OAuthClient::ADMIN_SCOPE);

    actingAs(User::factory()->create())->get("/oauth/authorize?{$query}")
        ->assertForbidden();
    expect(session()->has('authRequest'))->toBeFalse();
});

it('makes the admin client ask for exactly board:identity admin', function (string $scope) {
    $client = adminClient();
    [$query] = boardAuthorizeQuery($client, $scope);

    actingAs(admin())->get("/oauth/authorize?{$query}")->assertForbidden();
})->with(['board:identity', 'admin', 'admin board:identity mcp:use', 'board:identity admin mcp:use']);

it('a board client can’t get admin', function () {
    $client = boardClient();
    [$query] = boardAuthorizeQuery($client, OAuthClient::ADMIN_SCOPE);

    actingAs(admin())->get("/oauth/authorize?{$query}")->assertForbidden();
});

it('MCP consent never grants admin', function () {
    [$family, $users] = mcpFamily(['tasks']);
    $users[0]->forceFill(['is_platform_admin' => true])->save();
    mcpConnect($users[0], $family->id, ['tasks' => 'read']);

    expect(Token::query()->latest('created_at')->first()->scopes)->not->toContain('admin');
});

it('drops admin from tokens of a client that is not a platform admin client', function () {
    // Defence in depth at the scope repository: even a skipped-consent path can't mint admin elsewhere.
    $client = adminClient();
    $user = admin();
    [$query, $verifier] = boardAuthorizeQuery($client, OAuthClient::ADMIN_SCOPE);
    $location = actingAs($user)->get("/oauth/authorize?{$query}")->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
    $client->forceFill(['platform_admin' => false])->save();
    auth()->forgetGuards();

    $this->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $client->getKey(), 'client_secret' => $client->plainSecret,
        'redirect_uri' => 'https://onsplan.nl/api/auth/callback', 'code' => $params['code'], 'code_verifier' => $verifier,
    ])->assertOk();

    expect(Token::query()->latest('created_at')->first()->scopes)->not->toContain('admin');
});
