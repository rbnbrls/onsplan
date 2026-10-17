<?php

use App\Http\Controllers\OAuth\ApproveController;
use App\Http\Controllers\OAuth\AuthorizeController;
use App\Http\Middleware\McpFamilyContext;
use App\Http\Middleware\TrackLastSeen;
use App\Mcp\OnsplanServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Controllers\AccessTokenController;
use Laravel\Passport\Http\Controllers\DenyAuthorizationController;

// MCP server + its OAuth authorization server. Loaded from bootstrap/app.php OUTSIDE the `web` group:
// /mcp authenticates with bearer tokens (auth:api) and must never touch the session, while the
// browser-facing OAuth pages opt into `web` per route below. See docs/mcp.md.

// Passport's routes, registered by hand (Passport::ignoreRoutes() in AppServiceProvider) so two of them
// can be ours: GET /oauth/authorize always shows our consent page, POST /oauth/authorize turns the
// consent form into scopes. Only what the MCP flow needs is registered: no client/token JSON API, no
// personal access tokens, no device flow. Route names match Passport's (laravel/mcp's discovery
// document links to them by name).
Route::prefix('oauth')->name('passport.')->group(function () {
    Route::post('/token', [AccessTokenController::class, 'issueToken'])->middleware('throttle')->name('token');
    Route::get('/authorize', [AuthorizeController::class, 'authorize'])->middleware('web')->name('authorizations.authorize');
    Route::middleware(['web', 'auth'])->group(function () {
        Route::post('/authorize', [ApproveController::class, 'approve'])->name('authorizations.approve');
        Route::delete('/authorize', [DenyAuthorizationController::class, 'deny'])->name('authorizations.deny');
    });
});

// Discovery (.well-known/oauth-protected-resource + oauth-authorization-server) and RFC 7591 dynamic
// client registration (POST /oauth/register). Registration is public by design, so it is throttled.
Route::middleware('throttle:20,1')->group(fn () => Mcp::oauthRoutes());

// OpenAI app-submission domain verification: ChatGPT checks this token at the MCP host's origin root
// before it accepts the app. The token is public by design (anyone can fetch it), not a secret.
Route::get('/.well-known/openai-apps-challenge', fn () => response('DU5Q1SF_J4H6RvAetSYXSqQvonvovMkosuaHi2rA9bo', 200, ['Content-Type' => 'text/plain']));

Mcp::web('/mcp', OnsplanServer::class)
    ->middleware(['auth:api', McpFamilyContext::class, 'throttle:mcp', TrackLastSeen::class.':mcp']);
