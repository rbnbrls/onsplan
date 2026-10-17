<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Support\Auth\SocialLogin;
use App\Support\Translations;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

// "Continue with Google / Apple" (App\Support\Auth\SocialLogin). The providers are faked with Http::fake;
// Apple's client-secret JWT is signed with an EC P-256 key generated here, so no real keys are needed.

function socialGoogle(): void
{
    config(['services.google.client_id' => 'google-client', 'services.google.client_secret' => 'google-secret']);
}

/** Configure Apple with a fresh P-256 key. Returns the public key PEM (to verify signatures). */
function socialApple(): string
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $pem);
    config([
        'services.apple.client_id' => 'co.onsplan.web',
        'services.apple.team_id' => 'TEAM123456',
        'services.apple.key_id' => 'KEY1234567',
        // One line with literal \n escapes, exactly how .do/apply.sh stores it.
        'services.apple.private_key' => str_replace("\n", '\n', $pem),
    ]);

    return openssl_pkey_get_details($key)['key'];
}

function socialB64(string $bytes): string
{
    return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
}

function socialB64Decode(string $text): string
{
    return base64_decode(strtr($text, '-_', '+/'));
}

/** An unsigned JWT-shaped token carrying $claims (SocialLogin only reads Apple's payload, see the class). */
function socialIdToken(array $claims): string
{
    return socialB64('{"alg":"RS256"}').'.'.socialB64(json_encode($claims)).'.sig';
}

/** JWS raw R||S back to the DER openssl_verify expects. */
function socialRawToDer(string $raw): string
{
    $int = function (string $i) {
        $i = ltrim($i, "\0");
        if ($i === '' || ord($i[0]) > 0x7F) {
            $i = "\0".$i;
        }

        return "\x02".chr(strlen($i)).$i;
    };
    $body = $int(substr($raw, 0, 32)).$int(substr($raw, 32));

    return "\x30".chr(strlen($body)).$body;
}

/** Start the Google flow; returns the authorize URL's query params. */
function socialGoogleStart(): array
{
    $location = get('/auth/google/redirect')->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    return $query;
}

function socialGoogleFake(array $claims): void
{
    Http::preventStrayRequests();
    Http::fake([
        'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-123', 'id_token' => 'x']),
        'openidconnect.googleapis.com/v1/userinfo' => Http::response($claims + ['sub' => 'g-1', 'email_verified' => true]),
    ]);
}

// ---------------------------------------------------------------- buttons / config

it('hides the buttons and routes when no provider is configured', function () {
    config(['services.google.client_id' => null, 'services.google.client_secret' => null, 'services.apple.client_id' => null]);

    get('/login')->assertOk()->assertInertia(fn ($page) => $page->where('socialProviders', []));
    get('/register')->assertOk()->assertInertia(fn ($page) => $page->where('socialProviders', []));
    get('/auth/google/redirect')->assertNotFound();
    get('/auth/apple/redirect')->assertNotFound();
});

it('shows only the providers that are fully configured', function () {
    socialGoogle();
    config(['services.apple.client_id' => 'co.onsplan.web', 'services.apple.private_key' => null]);
    get('/login')->assertInertia(fn ($page) => $page->where('socialProviders', ['google']));

    socialApple();
    get('/register')->assertInertia(fn ($page) => $page->where('socialProviders', ['google', 'apple']));
});

// ---------------------------------------------------------------- Google

it('redirects to Google with state and a PKCE S256 challenge', function () {
    socialGoogle();
    $query = socialGoogleStart();
    $saved = session('social.google');

    expect($query['client_id'])->toBe('google-client')
        ->and($query['redirect_uri'])->toBe(url('/auth/google/callback'))
        ->and($query['response_type'])->toBe('code')
        ->and($query['scope'])->toBe('openid email profile')
        ->and($query['state'])->toBe($saved['state'])
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['code_challenge'])->toBe(socialB64(hash('sha256', $saved['verifier'], true)));
});

it('rejects a callback with a wrong or missing state', function () {
    socialGoogle();
    Http::fake();

    // Never started: nothing in the session.
    get('/auth/google/callback?code=abc&state=forged')->assertRedirect('/login')->assertSessionHasErrors('social');

    socialGoogleStart();
    get('/auth/google/callback?code=abc&state=wrong')->assertRedirect('/login')->assertSessionHasErrors('social');

    $this->assertGuest();
    Http::assertNothingSent();
});

it('sends a cancelled sign-in back to login with an error', function () {
    socialGoogle();
    Http::fake();
    $query = socialGoogleStart();

    get('/auth/google/callback?error=access_denied&state='.$query['state'])->assertRedirect('/login')->assertSessionHasErrors('social');
    $this->assertGuest();
});

it('turns a provider failure into a login error, never a 500', function () {
    socialGoogle();
    Http::fake(['oauth2.googleapis.com/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $query = socialGoogleStart();

    get('/auth/google/callback?code=abc&state='.$query['state'])->assertRedirect('/login')->assertSessionHasErrors('social');
    $this->assertGuest();
});

it('creates a verified account for a new Google user and sends them to onboarding', function () {
    socialGoogle();
    socialGoogleFake(['email' => 'New.Person@Gmail.com', 'name' => 'New Person', 'picture' => 'https://x/y.png']);
    $query = socialGoogleStart();

    get('/auth/google/callback?code=abc&state='.$query['state'])->assertRedirect(route('onboarding.index'));

    $user = User::where('email', 'new.person@gmail.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('New Person')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->locale)->toBe(app()->getLocale())
        ->and($user->socialAccounts()->sole()->only(['provider', 'provider_user_id', 'email']))
        ->toBe(['provider' => 'google', 'provider_user_id' => 'g-1', 'email' => 'new.person@gmail.com']);

    // The code exchange carried the PKCE verifier and our redirect URI.
    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://oauth2.googleapis.com/token'
        && $r['code'] === 'abc' && filled($r['code_verifier']) && $r['redirect_uri'] === url('/auth/google/callback'));
});

/** Run a Google sign-in whose verified email is $email (sub g-1). Returns the callback response. */
function socialGoogleSignIn(string $email, string $sub = 'g-1')
{
    socialGoogleFake(['email' => $email, 'sub' => $sub]);
    $query = socialGoogleStart();

    return get('/auth/google/callback?code=abc&state='.$query['state']);
}

it('never links by email alone: an existing account must confirm with its password first', function () {
    // The attack: someone registered the victim's address (onsplan doesn't force verification).
    socialGoogle();
    $attacker = User::factory()->unverified()->create(['email' => 'Victim@Example.com']);

    socialGoogleSignIn('victim@example.com')
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['social' => 'An account with this email already exists. Sign in with your password to connect Google.']);

    $this->assertGuest();
    expect(SocialAccount::count())->toBe(0)
        ->and($attacker->fresh()->email_verified_at)->toBeNull()
        ->and(User::count())->toBe(1);

    // The login form is pre-filled with the address the link is waiting for.
    get('/login')->assertInertia(fn ($page) => $page->where('email', 'victim@example.com'));
});

it('links and signs in straight away when the existing account already verified that email', function () {
    socialGoogle();
    session(['url.intended' => url('/oauth/authorize?client_id=board')]);
    $user = User::factory()->create(['email' => 'Ana@Example.com']); // verified (factory default)

    socialGoogleSignIn('ana@example.com')->assertRedirect(url('/oauth/authorize?client_id=board'));

    $this->assertAuthenticatedAs($user);
    expect($user->socialAccounts()->sole()->only(['provider', 'provider_user_id']))->toBe(['provider' => 'google', 'provider_user_id' => 'g-1'])
        ->and(User::count())->toBe(1)
        ->and(session(SocialLogin::PENDING))->toBeNull();
});

it('connects the parked provider after a password login to that account', function () {
    socialGoogle();
    session(['url.intended' => url('/oauth/authorize?client_id=board')]);
    $user = User::factory()->unverified()->create(['email' => 'ana@example.com']);
    socialGoogleSignIn('ana@example.com')->assertRedirect('/login');

    $this->post('/login', ['email' => 'ana@example.com', 'password' => 'password'])
        ->assertRedirect(url('/oauth/authorize?client_id=board')) // still lands where they were going
        ->assertSessionHas('status', 'Google connected. You can now sign in with it.');

    $this->assertAuthenticatedAs($user);
    expect($user->socialAccounts()->sole()->only(['provider', 'provider_user_id']))->toBe(['provider' => 'google', 'provider_user_id' => 'g-1'])
        ->and($user->fresh()->email_verified_at)->not->toBeNull()
        ->and(session(SocialLogin::PENDING))->toBeNull();
});

it('sends a newly connected user to their sign-in methods when they had nowhere else to go', function () {
    socialGoogle();
    User::factory()->unverified()->create(['email' => 'ana@example.com']);
    socialGoogleSignIn('ana@example.com');

    $this->post('/login', ['email' => 'ana@example.com', 'password' => 'password'])->assertRedirect(route('password.edit', absolute: false));
});

it('drops the parked link when someone else logs in', function () {
    socialGoogle();
    User::factory()->unverified()->create(['email' => 'ana@example.com']);
    $other = User::factory()->create(['email' => 'other@example.com']);
    socialGoogleSignIn('ana@example.com');

    $this->post('/login', ['email' => 'other@example.com', 'password' => 'password'])->assertRedirect();

    $this->assertAuthenticatedAs($other);
    expect(SocialAccount::count())->toBe(0)->and(session(SocialLogin::PENDING))->toBeNull();
});

it('drops the parked link once it expires', function () {
    socialGoogle();
    $user = User::factory()->unverified()->create(['email' => 'ana@example.com']);
    socialGoogleSignIn('ana@example.com');

    $this->travel(SocialLogin::PENDING_TTL + 60)->seconds();
    $this->post('/login', ['email' => 'ana@example.com', 'password' => 'password'])->assertRedirect();

    $this->assertAuthenticatedAs($user);
    expect(SocialAccount::count())->toBe(0);
});

it('asks an unverified account for the password again after a provider was disconnected', function () {
    socialGoogle();
    $user = User::factory()->unverified()->create(['email' => 'ana@example.com']);
    $google = $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-1', 'email' => 'ana@example.com']);
    $user->socialAccounts()->create(['provider' => 'apple', 'provider_user_id' => 'a-1']);
    actingAs($user)->delete("/settings/social-accounts/{$google->id}")->assertSessionHasNoErrors();
    auth()->logout();

    socialGoogleSignIn('ana@example.com')->assertRedirect('/login')->assertSessionHasErrors('social');

    $this->assertGuest();
    expect($user->socialAccounts()->where('provider', 'google')->exists())->toBeFalse();
});

it('does not link or create anything for an unverified provider email', function () {
    socialGoogle();
    $user = User::factory()->create(['email' => 'ana@example.com']);
    socialGoogleFake(['email' => 'ana@example.com', 'email_verified' => false]);
    $query = socialGoogleStart();

    get('/auth/google/callback?code=abc&state='.$query['state'])
        ->assertRedirect('/login')
        ->assertSessionHasErrors(['social' => Translations::get('auth.social_unverified')]);

    $this->assertGuest();
    expect(User::count())->toBe(1)->and(SocialAccount::count())->toBe(0);
});

it('signs a linked account in, even when its email changed, and honours url.intended', function () {
    socialGoogle();
    $user = User::factory()->create(['email' => 'old@example.com']);
    $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-1', 'email' => 'old@example.com']);
    socialGoogleFake(['email' => 'someone-else@example.com']);

    session(['url.intended' => url('/oauth/authorize?client_id=board')]);
    $query = socialGoogleStart();

    get('/auth/google/callback?code=abc&state='.$query['state'])->assertRedirect(url('/oauth/authorize?client_id=board'));
    $this->assertAuthenticatedAs($user);
    expect(User::count())->toBe(1);
});

// ---------------------------------------------------------------- Apple

/** Start the Apple flow. Returns [authorize query, decrypted state cookie value]. */
function socialAppleStart(): array
{
    $response = get('/auth/apple/redirect')->assertRedirect();
    parse_str((string) parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    $cookie = $response->getCookie(SocialLogin::APPLE_COOKIE);
    // SameSite=None + Secure is what lets the cookie ride along on Apple's cross-site POST.
    expect($cookie->getSameSite())->toBe('none')->and($cookie->isSecure())->toBeTrue()->and($cookie->isHttpOnly())->toBeTrue();

    return [$query, $cookie->getValue()];
}

it('redirects to Apple with form_post, state, nonce and a SameSite=None state cookie', function () {
    socialApple();
    [$query, $cookie] = socialAppleStart();
    $saved = json_decode($cookie, true);

    expect($query['client_id'])->toBe('co.onsplan.web')
        ->and($query['redirect_uri'])->toBe(url('/auth/apple/callback'))
        ->and($query['response_type'])->toBe('code')
        ->and($query['response_mode'])->toBe('form_post')
        ->and($query['scope'])->toBe('name email')
        ->and($query['state'])->toBe($saved['state'])
        ->and($query['nonce'])->toBe($saved['nonce']);
});

it('accepts Apple\'s cross-site form_post without CSRF, using the state cookie', function () {
    socialApple();
    session(['url.intended' => url('/oauth/authorize?client_id=board')]);
    [$query, $cookie] = socialAppleStart();

    Http::preventStrayRequests();
    Http::fake(['appleid.apple.com/auth/token' => Http::response(['id_token' => socialIdToken([
        'iss' => 'https://appleid.apple.com', 'aud' => 'co.onsplan.web', 'sub' => 'apple-1', 'nonce' => $query['nonce'],
        'email' => 'abc@privaterelay.appleid.com', 'email_verified' => 'true', 'is_private_email' => 'true',
    ])])]);

    // Make CSRF really run (the framework skips it under unit tests), and drop the session the way the
    // browser does: Apple's POST is cross-site, so the Lax session cookie isn't sent.
    app()->detectEnvironment(fn () => 'local');
    session()->flush();
    $this->post('/login', ['email' => 'x@example.com', 'password' => 'x'])->assertStatus(419); // CSRF is on

    $this->withCookie(SocialLogin::APPLE_COOKIE, $cookie)->post('/auth/apple/callback', [
        'code' => 'apple-code',
        'state' => $query['state'],
        'user' => json_encode(['name' => ['firstName' => 'Ioana', 'lastName' => 'Pop'], 'email' => 'abc@privaterelay.appleid.com']),
    ])->assertRedirect(route('onboarding.index'))->assertCookieExpired(SocialLogin::APPLE_COOKIE); // single use

    $user = User::where('email', 'abc@privaterelay.appleid.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->name)->toBe('Ioana Pop')
        ->and($user->email_verified_at)->not->toBeNull()
        ->and($user->socialAccounts()->sole()->provider_user_id)->toBe('apple-1')
        // url.intended travelled in the cookie and was put back into the new session.
        ->and(session('url.intended'))->toBe(url('/oauth/authorize?client_id=board'));

    // The token request authenticated with a freshly signed client-secret JWT.
    Http::assertSent(fn (HttpRequest $r) => $r['code'] === 'apple-code' && $r['client_id'] === 'co.onsplan.web'
        && count(explode('.', $r['client_secret'])) === 3 && $r['redirect_uri'] === url('/auth/apple/callback'));
});

it('rejects an Apple callback without the state cookie or with a wrong nonce', function () {
    socialApple();
    Http::fake(['appleid.apple.com/auth/token' => Http::response(['id_token' => socialIdToken([
        'iss' => 'https://appleid.apple.com', 'aud' => 'co.onsplan.web', 'sub' => 'apple-1', 'nonce' => 'replayed',
        'email' => 'a@example.com', 'email_verified' => true,
    ])])]);
    [$query] = socialAppleStart();

    $this->post('/auth/apple/callback', ['code' => 'c', 'state' => $query['state']])->assertRedirect('/login')->assertSessionHasErrors('social');
    Http::assertNothingSent();

    // With the cookie the code is exchanged, but the token's nonce isn't this sign-in's.
    [$query, $cookie] = socialAppleStart();
    $this->withCookie(SocialLogin::APPLE_COOKIE, $cookie)->post('/auth/apple/callback', ['code' => 'c', 'state' => $query['state']])
        ->assertRedirect('/login')->assertSessionHasErrors('social');
    Http::assertSentCount(1);

    $this->assertGuest();
    expect(User::count())->toBe(0);
});

it('sends an Apple sign-in without an email back to login', function () {
    socialApple();
    [$query, $cookie] = socialAppleStart();
    Http::fake(['appleid.apple.com/auth/token' => Http::response(['id_token' => socialIdToken([
        'iss' => 'https://appleid.apple.com', 'aud' => 'co.onsplan.web', 'sub' => 'apple-1', 'nonce' => $query['nonce'],
    ])])]);

    $this->withCookie(SocialLogin::APPLE_COOKIE, $cookie)->post('/auth/apple/callback', ['code' => 'c', 'state' => $query['state']])
        ->assertRedirect('/login')->assertSessionHasErrors('social');
    expect(User::count())->toBe(0);
});

it('rejects an Apple state cookie older than 10 minutes, even if the browser kept it', function () {
    socialApple();
    Http::fake();
    [$query, $cookie] = socialAppleStart();

    $this->travel(11)->minutes();
    $this->withCookie(SocialLogin::APPLE_COOKIE, $cookie)->post('/auth/apple/callback', ['code' => 'c', 'state' => $query['state']])
        ->assertRedirect('/login')->assertSessionHasErrors('social');
    Http::assertNothingSent();
});

it('signs Apple\'s client secret as an ES256 JWT with the right header and claims', function () {
    $publicKey = socialApple();
    $before = time();

    [$header, $claims, $signature] = explode('.', SocialLogin::appleClientSecret());
    $raw = socialB64Decode($signature);

    expect(json_decode(socialB64Decode($header), true))->toBe(['alg' => 'ES256', 'kid' => 'KEY1234567']);
    $claims = json_decode(socialB64Decode($claims), true);
    expect($claims['iss'])->toBe('TEAM123456')
        ->and($claims['sub'])->toBe('co.onsplan.web')
        ->and($claims['aud'])->toBe('https://appleid.apple.com')
        ->and($claims['iat'])->toBeGreaterThanOrEqual($before)
        ->and($claims['exp'] - $claims['iat'])->toBe(300)
        ->and(strlen($raw))->toBe(64);

    // The raw R||S, turned back into DER, verifies against the key's public half.
    $parts = explode('.', SocialLogin::appleClientSecret());
    expect(openssl_verify($parts[0].'.'.$parts[1], socialRawToDer(socialB64Decode($parts[2])), $publicKey, OPENSSL_ALGO_SHA256))->toBe(1);
});

// ---------------------------------------------------------------- Settings: disconnect

it('lists linked accounts and refuses to disconnect the last one', function () {
    $user = User::factory()->create();
    $account = $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-1', 'email' => 'a@example.com']);

    actingAs($user)->get('/settings/password')->assertInertia(fn ($page) => $page->where('socialAccounts.0.provider', 'google'));

    actingAs($user)->from('/settings/password')->delete("/settings/social-accounts/{$account->id}")
        ->assertRedirect('/settings/password')->assertSessionHasErrors('social');
    expect($account->fresh())->not->toBeNull();
});

it('disconnects one of two linked accounts, and only the user\'s own', function () {
    $user = User::factory()->create();
    $google = $user->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-1']);
    $user->socialAccounts()->create(['provider' => 'apple', 'provider_user_id' => 'a-1']);
    $other = User::factory()->create()->socialAccounts()->create(['provider' => 'google', 'provider_user_id' => 'g-2']);

    actingAs($user)->delete("/settings/social-accounts/{$other->id}")->assertNotFound();

    actingAs($user)->from('/settings/password')->delete("/settings/social-accounts/{$google->id}")
        ->assertRedirect('/settings/password')->assertSessionHasNoErrors();
    expect($google->fresh())->toBeNull()->and($user->socialAccounts()->count())->toBe(1);
});

// ---------------------------------------------------------------- analytics adoption (see AnalyticsTest)

it('adopts the onsplan.nl visitor id and first touch on a Google sign-up', function () {
    socialGoogle();
    $events = umamiOn();
    $vid = (string) Str::uuid();
    get("/login?fw_vid={$vid}&fw_src=onsplan.nl&fw_med=referral")->assertOk();

    socialGoogleSignIn('new.person@gmail.com')->assertRedirect(route('onboarding.index'));

    $user = User::where('email', 'new.person@gmail.com')->sole();
    expect($user->analytics_id)->toBe($vid)
        ->and($user->first_touch)->toMatchArray(['source' => 'onsplan.nl', 'medium' => 'referral'])
        ->and($events())->toBe([['signed_up', ['method' => 'google'], $vid, '/register']]);
});

it('carries the visitor id through Apple\'s cross-site callback into the new session', function () {
    socialApple();
    $events = umamiOn();
    $vid = (string) Str::uuid();
    get("/login?fw_vid={$vid}&fw_src=onsplan.nl")->assertOk();
    [$query, $cookie] = socialAppleStart();

    Http::fake(['appleid.apple.com/auth/token' => Http::response(['id_token' => socialIdToken([
        'iss' => 'https://appleid.apple.com', 'aud' => 'co.onsplan.web', 'sub' => 'apple-1', 'nonce' => $query['nonce'],
        'email' => 'abc@privaterelay.appleid.com', 'email_verified' => 'true',
    ])])]);
    session()->flush(); // Apple's POST is cross-site: the old session doesn't come along

    $this->withCookie(SocialLogin::APPLE_COOKIE, $cookie)->post('/auth/apple/callback', ['code' => 'apple-code', 'state' => $query['state']])
        ->assertRedirect(route('onboarding.index'));

    $user = User::where('email', 'abc@privaterelay.appleid.com')->sole();
    expect($user->analytics_id)->toBe($vid)
        ->and($user->first_touch['source'])->toBe('onsplan.nl')
        ->and($events())->toBe([['signed_up', ['method' => 'apple'], $vid, '/register']]);
});
