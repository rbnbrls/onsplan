<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Analytics;
use App\Support\Auth\SocialLogin;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Show the registration page.
     */
    public function create(): Response
    {
        return Inertia::render('auth/register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);

        return self::registered($request, $user);
    }

    /**
     * Everything that follows creating an account. Shared with "Sign in with Google / Apple"
     * (App\Support\Auth\SocialLogin) so a social sign-up gets exactly the same welcome.
     *
     * @param  string  $method  'password' | 'google' | 'apple', for the signed_up analytics event
     */
    public static function registered(Request $request, User $user, string $method = 'password'): RedirectResponse
    {
        self::adoptAnalytics($request, $user);
        Analytics::track($user, 'signed_up', ['method' => $method], '/register');
        // signed_up (server) is the reliable count; signup_completed (browser) is the same step inside the
        // visitor's own Umami visit, so the onsplan.nl -> sign-up funnel can follow it.
        Analytics::queue('signup_completed', ['method' => $method]);

        event(new Registered($user));

        Auth::login($user);
        // A parked "connect Google/Apple" link belongs to an existing account, never to a new one.
        $request->session()->forget(SocialLogin::PENDING);

        // If they arrived from an invitation link, finish that instead of onboarding. Otherwise new
        // users choose in onboarding (create / join / demo); RequireFamily keeps the app gated until then.
        if ($token = $request->session()->get('invite_token')) {
            return to_route('invite.show', $token);
        }

        return to_route('onboarding.index');
    }

    /**
     * Move what CaptureFirstTouch kept for this guest onto their new account, then forget it. The
     * onsplan.nl visitor id becomes their analytics_id (so their pre-signup visits are the same person
     * in Umami), unless another account already has it: e.g. a second sign-up from the same browser keeps
     * its own fresh id instead of merging two people into one.
     */
    private static function adoptAnalytics(Request $request, User $user): void
    {
        $analytics = (array) $request->session()->pull('analytics', []);

        $visitor = $analytics['visitor_id'] ?? null;
        if (is_string($visitor) && ! User::where('analytics_id', $visitor)->exists()) {
            $user->analytics_id = $visitor;
        }
        if (is_array($analytics['first_touch'] ?? null)) {
            $user->first_touch = $analytics['first_touch'];
        }
        if ($user->isDirty()) {
            $user->save();
        }
    }
}
