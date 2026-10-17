<?php

use App\Support\Mcp\McpTools;
use Laravel\Passport\Token;

use function Pest\Laravel\actingAs;

// The consent flow only cares that a module registered tools, not which ones: make sure the two modules
// these tests grant are registered even before (or regardless of whether) they ship real tools.
beforeEach(function () {
    foreach (['tasks' => 'Tasks', 'shopping' => 'Shopping'] as $id => $name) {
        if (! isset(McpTools::modules()[$id])) {
            McpTools::register($id, $name, []);
        }
    }
});

it('serves the discovery documents pointing at the authorization server', function () {
    config(['mcp.authorization_server' => 'https://onsplan.nl']);

    $this->getJson('/.well-known/oauth-protected-resource/mcp')->assertOk()
        ->assertJsonPath('resource', url('/mcp'))
        ->assertJsonPath('authorization_servers', ['https://onsplan.nl']);

    $this->getJson('/.well-known/oauth-authorization-server')->assertOk()
        ->assertJsonPath('registration_endpoint', url('oauth/register'))
        ->assertJsonPath('authorization_endpoint', route('passport.authorizations.authorize'))
        ->assertJsonPath('code_challenge_methods_supported', ['S256']);
});

it('registers a public client', function () {
    mcpKeys();
    $this->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => ['claude://callback']])
        ->assertCreated()->assertJsonPath('token_endpoint_auth_method', 'none');
});

it('always shows consent and issues exactly the scopes the person picked', function () {
    [$family, $users] = mcpFamily(['tasks', 'shopping']);

    $token = mcpConnect($users[0], $family->id, ['tasks' => 'write', 'shopping' => 'read']);
    expect(Token::first()->scopes)->toEqualCanonicalizing(['mcp:use', "family:{$family->id}", 'tasks:read', 'tasks:write', 'shopping:read'])
        ->and($token['refresh_token'])->not->toBeEmpty();

    // A second connection of the same client must still show the consent page (no silent reuse).
    $clientId = Token::first()->client_id;
    [$query] = mcpAuthorizeQuery($clientId);
    actingAs($users[0])->get("/oauth/authorize?{$query}")->assertOk()
        ->assertInertia(fn ($p) => $p->component('oauth/authorize')
            ->where('families.0.id', $family->id)
            ->where('families.0.modules', fn ($m) => collect($m)->pluck('id')->sort()->values()->all() === ['shopping', 'tasks']));
});

it('keeps the family scope on refresh', function () {
    [$family, $users] = mcpFamily(['tasks']);
    $token = mcpConnect($users[0], $family->id, ['tasks' => 'read']);
    $clientId = Token::first()->client_id;

    $this->post('/oauth/token', ['grant_type' => 'refresh_token', 'refresh_token' => $token['refresh_token'], 'client_id' => $clientId])->assertOk();
    expect(Token::latest('created_at')->get()->pluck('scopes')->unique()->values()->all())
        ->toEqual([['mcp:use', "family:{$family->id}", 'tasks:read']]);
});

it('rejects a family the person is not in, and a module that family has not enabled', function () {
    [$family, $users] = mcpFamily(['tasks']);
    [$other] = mcpFamily(['tasks']);
    [$query] = mcpAuthorizeQuery(mcpClient());

    actingAs($users[0])->get("/oauth/authorize?{$query}")->assertOk();
    actingAs($users[0])->post('/oauth/authorize', ['auth_token' => session('authToken'), 'family_id' => $other->id])
        ->assertSessionHasErrors('family_id');

    actingAs($users[0])->get("/oauth/authorize?{$query}")->assertOk();
    actingAs($users[0])->post('/oauth/authorize', ['auth_token' => session('authToken'), 'family_id' => $family->id, 'access' => ['shopping' => 'read']])
        ->assertSessionHasErrors('access');
    expect(Token::count())->toBe(0);
});

it('denies with access_denied', function () {
    [, $users] = mcpFamily(['tasks']);
    [$query] = mcpAuthorizeQuery(mcpClient());
    actingAs($users[0])->get("/oauth/authorize?{$query}")->assertOk();

    $location = actingAs($users[0])->delete('/oauth/authorize', ['auth_token' => session('authToken')])->headers->get('Location');
    expect($location)->toContain('error=access_denied');
});

it('sends a guest to login first', function () {
    mcpKeys();
    [$query] = mcpAuthorizeQuery(mcpClient());
    $this->get("/oauth/authorize?{$query}")->assertRedirect('/login');
});
