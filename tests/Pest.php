<?php

use App\Models\Family;
use App\Models\OAuthClient;
use App\Models\User;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\ClientRepository;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

// Unit tests get the app booted too (no RefreshDatabase: they're pure/no-DB), but NOT via RefreshDatabase.
// Modules\* classes (e.g. Modules\Calendar\Occurrences) only resolve once ModuleServiceProvider has run
// (it remaps each module's StudlyCase namespace onto its lowercase `modules/<id>` folder at runtime; plain
// Composer PSR-4 is case-sensitive and never finds e.g. modules/Calendar for namespace Modules\Calendar).
// `php artisan test` always runs a fresh subprocess per invocation, so without this a module's Unit test
// only passed by accident when another booted-app test happened to run first in the same process (e.g.
// alphabetically before it). Booting here makes every Unit test deterministic regardless of run order.
pest()->extend(TestCase::class)->in('Unit');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Hosted-mode Umami (App\Support\Analytics) with its collect endpoint faked. Returns the events sent so far
 * as a closure, each one [name, data, id, url], so tests can assert on exactly what reached Umami.
 */
function umamiOn(): Closure
{
    config(['services.umami.website_id' => 'site-123', 'services.umami.host' => 'https://analytics.dragonpie.co']);
    Http::fake(['analytics.dragonpie.co/*' => Http::response(['ok' => true])]);

    return fn () => Http::recorded(fn ($request) => str_ends_with($request->url(), '/api/send'))
        ->map(fn ($pair) => [$pair[0]['payload']['name'], (array) $pair[0]['payload']['data'], $pair[0]['payload']['id'], $pair[0]['payload']['url']])
        ->values()->all();
}

/*
|--------------------------------------------------------------------------
| MCP helpers (tests/Feature/Mcp)
|--------------------------------------------------------------------------
| Tokens are obtained through the REAL OAuth flow (dynamic client registration -> consent -> code
| exchange with PKCE), so every MCP test also exercises the scope narrowing the consent screen does.
*/

// Passport needs an RSA keypair to sign tokens. Generated once per process and fed through config, so
// neither tests nor CI need key files on disk (storage/*.key is gitignored; prod injects env secrets).
function mcpKeys(): void
{
    static $keys = null;
    if ($keys === null) {
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $private);
        $keys = [$private, openssl_pkey_get_details($res)['key']];
    }
    config(['passport.private_key' => $keys[0], 'passport.public_key' => $keys[1]]);
}

/** A family whose $modules are enabled, with $members users (first is admin), active in session. */
function mcpFamily(array $modules = [], int $members = 1): array
{
    mcpKeys();
    $users = User::factory()->count($members)->create();
    $family = Family::factory()->create(['owner_id' => $users[0]->id]);
    foreach ($users as $i => $u) {
        $family->members()->attach($u, ['role' => $i === 0 ? 'admin' : 'member']);
    }
    session(['active_family_id' => $family->id]);
    test()->artisan('modules:migrate')->assertExitCode(0);
    foreach ($modules as $m) {
        app(ModuleRegistry::class)->enable($m, $family->id);
    }

    return [$family, $users];
}

/** Register a public MCP client the way Claude does. Returns its client_id. */
function mcpClient(string $redirect = 'https://claude.ai/api/mcp/auth_callback'): string
{
    return test()->postJson('/oauth/register', ['client_name' => 'Claude', 'redirect_uris' => [$redirect]])
        ->assertCreated()->json('client_id');
}

/** PKCE authorize query for $clientId. Returns [query, verifier]. */
function mcpAuthorizeQuery(string $clientId, string $redirect = 'https://claude.ai/api/mcp/auth_callback'): array
{
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return [http_build_query([
        'client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code',
        'scope' => 'mcp:use', 'state' => 'xyz', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]), $verifier];
}

/** Confidential authorization-code client for the module-requests board, marked first_party on its row. */
function boardClient(string $redirect = 'https://onsplan.nl/api/auth/callback'): OAuthClient
{
    mcpKeys();
    /** @var OAuthClient $client */
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('onsplan board', [$redirect]);
    $client->forceFill(['first_party' => true])->save();

    return $client;
}

/** The admin.onsplan.nl client: a board client also flagged platform_admin (same callback, so boardToken works). */
function adminClient(): OAuthClient
{
    $client = boardClient();
    $client->forceFill(['platform_admin' => true])->save();

    return $client;
}

/** PKCE authorize query for a board client. Returns [query, verifier]. */
function boardAuthorizeQuery(OAuthClient $client, string $scope = 'board:identity', string $redirect = 'https://onsplan.nl/api/auth/callback'): array
{
    $verifier = Str::random(64);
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    return [http_build_query([
        'client_id' => $client->getKey(), 'redirect_uri' => $redirect, 'response_type' => 'code',
        'scope' => $scope, 'state' => 'xyz', 'code_challenge' => $challenge, 'code_challenge_method' => 'S256',
    ]), $verifier];
}

/** Full board sign-in: authorize (consent skipped) and exchange the code. Returns the token response. */
function boardToken(User $user, OAuthClient $client, string $scope = 'board:identity'): array
{
    // Same isolation as mcpConnect() below: fresh route controllers, the web guard for the
    // authorize page, a session-less token request.
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
    auth()->forgetGuards();
    auth()->shouldUse('web');
    [$query, $verifier] = boardAuthorizeQuery($client, $scope);
    $location = test()->actingAs($user, 'web')->get("/oauth/authorize?{$query}")->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $params);
    auth()->forgetGuards();

    return test()->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $client->getKey(), 'client_secret' => $client->plainSecret,
        'redirect_uri' => 'https://onsplan.nl/api/auth/callback', 'code' => $params['code'], 'code_verifier' => $verifier,
    ])->assertOk()->json();
}

/**
 * Full connect: register, consent with $access for $familyId, exchange the code.
 * Returns the token response (access_token, refresh_token, ...).
 */
function mcpConnect(User $user, int $familyId, array $access = []): array
{
    // Routes cache their controller instance, and Passport's authorize controller holds the session guard
    // it was built with (= the previous request's user). Octane flushes route controllers after every
    // request in production (ApplicationGateway); the test app does not, so do the same here.
    foreach (app('router')->getRoutes() as $route) {
        $route->flushController();
    }
    // A previous bearer call made `api` the default guard (auth:api calls shouldUse); consent is a web page.
    auth()->forgetGuards();
    auth()->shouldUse('web');
    mcpKeys(); // here too, not only in mcpFamily(): a caller with its own family (e.g. the seeded demo) needs them
    $clientId = mcpClient();
    [$query, $verifier] = mcpAuthorizeQuery($clientId);
    test()->actingAs($user, 'web')->get("/oauth/authorize?{$query}")->assertOk();
    $location = test()->actingAs($user, 'web')->post('/oauth/authorize', [
        'auth_token' => session('authToken'), 'family_id' => $familyId, 'access' => $access,
    ])->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $params);

    // The token endpoint is a fresh, session-less client request.
    auth()->forgetGuards();

    return test()->post('/oauth/token', [
        'grant_type' => 'authorization_code', 'client_id' => $clientId, 'code' => $params['code'],
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback', 'code_verifier' => $verifier,
    ])->assertOk()->json();
}

/** One JSON-RPC call to /mcp with a bearer token. Returns the decoded response. */
function mcpCall(string $token, string $method, array $params = []): TestResponse
{
    auth()->forgetGuards(); // never let a previous actingAs() leak into a bearer request

    return test()->withHeaders(['Authorization' => "Bearer {$token}", 'Accept' => 'application/json, text/event-stream'])
        ->postJson('/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params]);
}

/** Call a tool; returns [isError, decoded JSON payload or text]. */
function mcpTool(string $token, string $name, array $args = []): array
{
    // A JSON-RPC level error (e.g. an unknown/unlisted tool) comes back as HTTP 400 with an `error` body.
    $res = mcpCall($token, 'tools/call', ['name' => $name, 'arguments' => (object) $args])->json();
    if (isset($res['error'])) {
        return [true, $res['error']['message']];
    }
    $text = $res['result']['content'][0]['text'] ?? '';
    $decoded = json_decode($text, true);

    return [(bool) ($res['result']['isError'] ?? false), $decoded ?? $text];
}

/** Names of the tools the token sees. */
function mcpToolNames(string $token): array
{
    return collect(mcpCall($token, 'tools/list')->assertOk()->json('result.tools'))->pluck('name')->sort()->values()->all();
}

/**
 * Put a family past its free period, so an unpaid one hits the paywall (Billing::paywallDue): it used
 * enough modules (engaged_at) and is older than the grace hours. Tests about the locked state call this.
 */
function pastFreePeriod(App\Models\Family $family): App\Models\Family
{
    $family->forceFill(['created_at' => now()->subDays(2), 'engaged_at' => now()->subDay()])->save();

    return $family;
}
