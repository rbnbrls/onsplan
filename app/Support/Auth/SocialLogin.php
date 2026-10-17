<?php

namespace App\Support\Auth;

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\RegisteredUserController;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * "Sign in with Google / Apple": build the authorize redirect, turn the callback into a verified identity
 * (provider user id + email), then sign that person in, link them, or create their account.
 *
 * ponytail: both providers go through Laravel's Http client + openssl instead of Socialite (same style as
 * App\Support\Billing\Billing): per provider it's one authorize URL and one code exchange. We only want
 * identity, so no tokens and no profile picture are ever stored. Switch to Socialite if many more
 * providers, or real API access on the user's behalf, come along.
 */
class SocialLogin
{
    public const PROVIDERS = ['google', 'apple'];

    /** Carries Apple's state/nonce across its cross-site POST callback. See appleRedirect(). */
    public const APPLE_COOKIE = 'apple_oauth';

    // Session key + lifetime of a link waiting for the account's password (see signIn()).
    public const PENDING = 'social.pending';

    public const PENDING_TTL = 600;

    /** @return list<string> providers whose config is complete, i.e. whose button shows. */
    public static function enabled(): array
    {
        return array_values(array_filter(self::PROVIDERS, fn ($provider) => self::configured($provider)));
    }

    // Every value must be set: a half-configured provider would only produce a broken redirect.
    public static function configured(string $provider): bool
    {
        $config = config("services.{$provider}");

        return in_array($provider, self::PROVIDERS, true) && is_array($config) && collect($config)->every(fn ($v) => filled($v));
    }

    // ---------------------------------------------------------------- Google (OAuth 2.0 / OIDC, PKCE)

    public static function googleRedirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $verifier = Str::random(64);
        // Google comes back with a top-level GET, which carries our SameSite=Lax session cookie, so the
        // session is the right place for state + the PKCE verifier (unlike Apple, below).
        $request->session()->put('social.google', ['state' => $state, 'verifier' => $verifier]);

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => url('/auth/google/callback'),
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'code_challenge' => self::base64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            // Families often share a device: always let them pick which Google account to use.
            'prompt' => 'select_account',
        ], '', '&', PHP_QUERY_RFC3986));
    }

    /** @return array{sub: string, email: ?string, email_verified: bool, name: ?string} */
    public static function googleIdentity(Request $request): array
    {
        // pull(): the state is single-use, so a replayed callback URL fails.
        $saved = $request->session()->pull('social.google');
        // `error` = they cancelled on Google's screen (or Google refused); a state mismatch = forged/stale.
        if ($request->filled('error') || ! $request->filled('code') || ! self::stateMatches($saved['state'] ?? null, $request->query('state'))) {
            throw new SocialLoginException('auth.social_failed');
        }

        $accessToken = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'grant_type' => 'authorization_code',
            'code' => $request->query('code'),
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => url('/auth/google/callback'),
            'code_verifier' => $saved['verifier'],
        ])->throw()->json('access_token');

        // The userinfo endpoint returns the same claims as the ID token, already validated by Google, so
        // there's no JWT to verify on our side.
        $claims = Http::withToken((string) $accessToken)->get('https://openidconnect.googleapis.com/v1/userinfo')->throw()->json();

        return [
            'sub' => (string) ($claims['sub'] ?? ''),
            'email' => $claims['email'] ?? null,
            'email_verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'name' => $claims['name'] ?? null,
        ];
    }

    // ---------------------------------------------------------------- Apple (form_post, ES256 client secret)

    public static function appleRedirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);
        $nonce = Str::random(40);

        // Apple must use response_mode=form_post when asking for name/email, i.e. it returns with a
        // CROSS-SITE POST. Browsers don't send our SameSite=Lax session cookie on that, so the callback runs
        // in a brand-new session and can't see anything we put in the old one. Everything the callback
        // needs therefore rides in this short-lived cookie: state + nonce, plus where to send the person
        // afterwards (url.intended, e.g. the board's OAuth authorize page; a pending family invite), which
        // would otherwise be stranded in the old session. SameSite=None + Secure is what lets it travel
        // on Apple's POST; Laravel's EncryptCookies middleware encrypts it, so it can't be read or forged.
        $payload = json_encode([
            'state' => $state,
            'nonce' => $nonce,
            // Checked server-side too: the cookie's 10-minute lifetime is only the browser's promise.
            'expires' => now()->getTimestamp() + 600,  // now(), not time(): honours the test clock (travel()).
            'intended' => $request->session()->get('url.intended'),
            'invite' => $request->session()->get('invite_token'),
            // The guest's onsplan.nl visitor id + first touch (CaptureFirstTouch), so an Apple sign-UP
            // can still adopt them in the new session. Small: two capped strings and a short array.
            'analytics' => $request->session()->get('analytics'),
        ]);

        return redirect()->away('https://appleid.apple.com/auth/authorize?'.http_build_query([
            'client_id' => config('services.apple.client_id'),
            'redirect_uri' => url('/auth/apple/callback'),
            'response_type' => 'code',
            'response_mode' => 'form_post',
            'scope' => 'name email',
            'state' => $state,
            'nonce' => $nonce,
        ], '', '&', PHP_QUERY_RFC3986))->withCookie(
            cookie(self::APPLE_COOKIE, $payload, 10, '/auth/apple', null, true, true, false, 'none')
        );
    }

    /** @return array{sub: string, email: ?string, email_verified: bool, name: ?string} */
    public static function appleIdentity(Request $request): array
    {
        // Single use, like Google's session pull(): SocialLoginController deletes the cookie on every answer.
        $saved = json_decode((string) $request->cookie(self::APPLE_COOKIE), true);

        if ($request->filled('error') || ! $request->filled('code') || ! is_array($saved) || ($saved['expires'] ?? 0) < now()->getTimestamp()
            || ! self::stateMatches($saved['state'] ?? null, $request->input('state'))) {
            throw new SocialLoginException('auth.social_failed');
        }

        // Put back what the old session held (see appleRedirect()), so signIn() lands them where they meant to go.
        if (filled($saved['intended'] ?? null)) {
            $request->session()->put('url.intended', $saved['intended']);
        }
        if (filled($saved['invite'] ?? null)) {
            $request->session()->put('invite_token', $saved['invite']);
        }
        if (is_array($saved['analytics'] ?? null)) {
            $request->session()->put('analytics', $saved['analytics']);
        }

        $idToken = Http::asForm()->post('https://appleid.apple.com/auth/token', [
            'grant_type' => 'authorization_code',
            'code' => $request->input('code'),
            'client_id' => config('services.apple.client_id'),
            'client_secret' => self::appleClientSecret(),
            'redirect_uri' => url('/auth/apple/callback'),
        ])->throw()->json('id_token');

        // We decode the id_token WITHOUT verifying its signature against Apple's JWKS on purpose: it came
        // straight from Apple's token endpoint, over TLS, in answer to a request we authenticated with our
        // own client secret (OIDC Core 3.1.3.7 allows exactly this). The id_token Apple also POSTs in the
        // form is ignored: that one passed through the browser. We still check it was minted for us
        // (iss/aud) and for THIS sign-in (nonce).
        $claims = json_decode(self::base64urlDecode(explode('.', (string) $idToken)[1] ?? ''), true);
        if (! is_array($claims)
            || ($claims['iss'] ?? null) !== 'https://appleid.apple.com'
            || ($claims['aud'] ?? null) !== config('services.apple.client_id')
            || ! self::stateMatches($saved['nonce'] ?? null, $claims['nonce'] ?? null)) {
            throw new SocialLoginException('auth.social_failed');
        }

        // Apple sends the name only on the person's very first authorization, as a `user` JSON form field
        // (never in the token). It comes through the browser, but it's only their own display name.
        $user = json_decode((string) $request->input('user'), true);
        $name = trim(($user['name']['firstName'] ?? '').' '.($user['name']['lastName'] ?? ''));

        // is_private_email (a @privaterelay.appleid.com address) needs no special handling: the relay
        // forwards our mail, and Apple marks it verified.
        return [
            'sub' => (string) ($claims['sub'] ?? ''),
            'email' => $claims['email'] ?? null,
            'email_verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'name' => $name !== '' ? $name : null,
        ];
    }

    /**
     * Apple's "client secret" is a short-lived ES256 JWT signed with our Sign in with Apple key. Five
     * minutes is plenty: a fresh one is made for every code exchange (Apple allows up to six months).
     */
    public static function appleClientSecret(): string
    {
        $now = time();
        $input = self::base64url(json_encode(['alg' => 'ES256', 'kid' => config('services.apple.key_id')]))
            .'.'.self::base64url(json_encode([
                'iss' => config('services.apple.team_id'),
                'iat' => $now,
                'exp' => $now + 300,
                'aud' => 'https://appleid.apple.com',
                'sub' => config('services.apple.client_id'),
            ], JSON_UNESCAPED_SLASHES));

        // .do/apply.sh stores multi-line secrets on one line with literal \n escapes (like the Passport keys).
        $key = str_replace('\n', "\n", (string) config('services.apple.private_key'));
        if (! @openssl_sign($input, $der, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('APPLE_PRIVATE_KEY is not a valid EC private key.');
        }

        return $input.'.'.self::base64url(self::derToRawSignature($der));
    }

    // ---------------------------------------------------------------- shared

    /**
     * Sign the person in: a linked account wins; else an account with this (provider-verified) email is
     * linked straight away if it verified that email itself, or waits for its password if it never did
     * (see below); else a new account is created through the normal registration path.
     *
     * @param  array{sub: string, email: ?string, email_verified: bool, name: ?string}  $identity
     */
    public static function signIn(Request $request, string $provider, array $identity): RedirectResponse
    {
        if ($identity['sub'] === '') {
            throw new SocialLoginException('auth.social_failed');
        }

        $account = SocialAccount::where('provider', $provider)->where('provider_user_id', $identity['sub'])->first();
        if ($account) {
            Auth::login($account->user);

            return AuthenticatedSessionController::signedIn($request);
        }

        $email = Str::lower(trim((string) $identity['email']));
        if ($email === '') {
            throw new SocialLoginException('auth.social_no_email');
        }
        // Only a provider-verified email may link to (or claim) an account: otherwise anyone could add an
        // unverified address at the provider and walk into someone else's onsplan account.
        if (! $identity['email_verified']) {
            throw new SocialLoginException('auth.social_unverified');
        }

        $link = ['provider' => $provider, 'provider_user_id' => $identity['sub'], 'email' => $email];

        // Case-insensitive: older accounts may have been registered with capitals in the address.
        if ($user = User::whereRaw('lower(email) = ?', [$email])->first()) {
            // The account already proved it owns this address (clicked our verification link) and the provider
            // proves the person signing in owns it too: same person, so link and sign in, no extra step.
            // Safe because changing the profile email resets email_verified_at (Settings\ProfileController).
            if ($user->hasVerifiedEmail()) {
                $user->socialAccounts()->create($link);
                Auth::login($user);

                return AuthenticatedSessionController::signedIn($request);
            }

            // Unverified account: NEVER link by email alone. onsplan doesn't make people verify their
            // address, so anyone can register (or switch their profile to) someone else's email in advance;
            // auto-linking would then log the real owner into the account the attacker controls. So the person
            // proves they own THIS account with its password first: we park the link, send them to the login
            // form, and completePendingLink() finishes it after a successful password login.
            $request->session()->put(self::PENDING, encrypt($link + ['user_id' => $user->id, 'expires' => now()->getTimestamp() + self::PENDING_TTL]));

            throw new SocialLoginException('auth.social_link_password');
        }

        // One transaction: a user row without its link (e.g. two racing callbacks hitting the unique
        // index) would leave a passwordless account nobody can reach.
        $user = DB::transaction(function () use ($identity, $email, $link) {
            $user = new User;
            $user->forceFill([
                'name' => Str::limit($identity['name'] ?: Str::before($email, '@'), 255, ''),
                'email' => $email,
                'email_verified_at' => now(),
                // The password column stays NOT NULL: a random one nobody knows means "no password". They can
                // still set one through "Forgot password?" (the address is verified, so the link arrives).
                'password' => Hash::make(Str::random(64)),
                'locale' => app()->getLocale(),
            ])->save();
            $user->socialAccounts()->create($link);

            return $user;
        });

        // Same Registered event, login, analytics adoption and onboarding/invite redirect as the sign-up form.
        return RegisteredUserController::registered($request, $user, $provider);
    }

    /**
     * The parked "sign in with your password to connect" link (see signIn()), if it hasn't expired.
     *
     * @return array{provider: string, provider_user_id: string, email: string, user_id: int, expires: int}|null
     */
    public static function pendingLink(Request $request): ?array
    {
        try {
            $pending = decrypt((string) $request->session()->get(self::PENDING));
        } catch (\Throwable) {
            return null;
        }

        return is_array($pending) && ($pending['expires'] ?? 0) >= now()->getTimestamp() ? $pending : null;
    }

    /**
     * Called after a successful PASSWORD login (only there: the password is the proof of ownership).
     * Consumes the parked link whatever happens, and creates it only for the same user and email.
     * Returns the provider that got connected, or null.
     */
    public static function completePendingLink(Request $request, User $user): ?string
    {
        $pending = self::pendingLink($request);
        $request->session()->forget(self::PENDING);

        if (! $pending || $pending['user_id'] !== $user->id || Str::lower($user->email) !== $pending['email']
            || SocialAccount::where('provider', $pending['provider'])->where('provider_user_id', $pending['provider_user_id'])->exists()) {
            return null;
        }

        $user->socialAccounts()->create(Arr::only($pending, ['provider', 'provider_user_id', 'email']));
        // The password proved they own the account, the provider proved they own the address.
        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $pending['provider'];
    }

    public static function label(string $provider): string
    {
        return $provider === 'apple' ? 'Apple' : 'Google';
    }

    private static function stateMatches(mixed $expected, mixed $given): bool
    {
        return is_string($expected) && $expected !== '' && is_string($given) && hash_equals($expected, $given);
    }

    /**
     * openssl_sign() returns an ECDSA signature as DER (SEQUENCE { INTEGER r, INTEGER s }); JWS ES256
     * wants the raw 64-byte r || s, each left-padded to 32 bytes. A P-256 DER signature is at most 72
     * bytes, so every length here fits in one byte (short form).
     */
    private static function derToRawSignature(string $der): string
    {
        $raw = '';
        $offset = 2; // skip SEQUENCE tag + length
        for ($i = 0; $i < 2; $i++) {
            $length = ord($der[$offset + 1]);
            // DER pads a leading 0x00 when the high bit is set; strip it, then pad to 32.
            $raw .= str_pad(ltrim(substr($der, $offset + 2, $length), "\0"), 32, "\0", STR_PAD_LEFT);
            $offset += 2 + $length;
        }

        return $raw;
    }

    private static function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private static function base64urlDecode(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/'));
    }
}
