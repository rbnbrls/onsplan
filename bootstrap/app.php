<?php

use App\Http\Middleware\BlockDemo;
use App\Http\Middleware\CaptureFirstTouch;
use App\Http\Middleware\EnsureModuleEnabled;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireFamily;
use App\Http\Middleware\SetActiveFamily;
use App\Http\Middleware\SetLocale;
use App\Http\Middleware\ShareModules;
use App\Http\Middleware\ShareRealtime;
use App\Http\Middleware\ShareTheme;
use App\Http\Middleware\TrackLastSeen;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        // MCP server + OAuth routes live outside the `web` group (bearer auth, no session). See routes/mcp.php.
        // routes/board.php: the module-requests board's bearer endpoints, same reasoning.
        // routes/admin.php: admin.onsplan.nl's bearer endpoints, same reasoning.
        then: function () {
            require base_path('routes/mcp.php');
            require base_path('routes/board.php');
            require base_path('routes/admin.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        // App Platform terminates TLS at its load balancer and forwards plain HTTP to the container,
        // so without this Laravel builds http:// URLs and secure cookies never stick. '*' is safe here:
        // the container is only reachable through the LB, never directly.
        $middleware->trustProxies(at: '*');

        $middleware->web(append: [
            // SetLocale runs BEFORE HandleInertiaRequests on purpose: Inertia resolves share() at the
            // start of the chain, and the translations once-prop key needs the final locale right
            // then (a closure can't key a once-prop). Only needs the session user, already there.
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SetActiveFamily::class,
            ShareTheme::class,
            ShareModules::class,
            ShareRealtime::class,
            // Last active (web) for admin.onsplan.nl; throttled to one write per user per 5 minutes.
            TrackLastSeen::class,
            // Guests only: the onsplan.nl visitor id + first-touch source, kept until sign-up adopts them.
            CaptureFirstTouch::class,
        ]);

        // Stripe can't send a CSRF token; the webhook authenticates by its Stripe-Signature instead.
        // Sign in with Apple POSTs back from appleid.apple.com (no token possible); it's protected by the
        // OAuth state in its own encrypted cookie instead (see App\Support\Auth\SocialLogin).
        $middleware->validateCsrfTokens(except: ['stripe/webhook', 'auth/apple/callback', 'integrations/microsoft/calendar/webhook']);

        $middleware->alias([
            'module' => EnsureModuleEnabled::class,
            'family' => RequireFamily::class,
            'not-demo' => BlockDemo::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
