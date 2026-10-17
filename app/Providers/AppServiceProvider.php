<?php

namespace App\Providers;

use App\Http\Controllers\OAuth\AuthorizeController;
use App\Mcp\Tools\GetFamily;
use App\Mcp\Tools\GetToday;
use App\Models\Family;
use App\Models\OAuthClient;
use App\Models\User;
use App\Support\Mcp\McpTools;
use App\Support\Mcp\ScopeRepository;
use App\Support\Modules\Hooks;
use App\Support\Modules\ModuleRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Laravel\Passport\Bridge\ScopeRepository as PassportScopeRepository;
use Laravel\Passport\Client;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ModuleRegistry::class, function () {
            return new ModuleRegistry(base_path('modules'));
        });

        $this->app->singleton(Hooks::class);

        // MCP / OAuth. We register Passport's routes ourselves (routes/mcp.php) to swap in our consent
        // controllers and leave out the JSON client/token API. Passport resolves the scope repository from
        // the container when it builds the authorization server, so this binding is what teaches it the
        // `family:<id>` scope (see App\Support\Mcp\ScopeRepository). A plain binding, Octane-safe.
        Passport::ignoreRoutes();
        $this->app->bind(PassportScopeRepository::class, ScopeRepository::class);
        // Passport gives its own AuthorizationController the session guard contextually; our subclass
        // (always-show-consent) needs the same binding.
        $this->app->when(AuthorizeController::class)
            ->needs(StatefulGuard::class)
            ->give(fn () => Auth::guard(config('passport.guard')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Short access tokens; clients refresh silently. A connector unused for 30 days re-runs consent.
        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(30));

        // Module scopes come from McpTools, which module providers fill during their boot(), so the list
        // is built once everything has booted. `mcp:use` is laravel/mcp's base scope (it also adds it when
        // the discovery routes load; listing it here keeps the result independent of that order).
        // `board:identity`: the module-requests board's sign-in (user id, verified-email flag, family names).
        // `admin`: admin.onsplan.nl's read-only platform API; only the platform-admin client can get it (ScopeRepository).
        $this->app->booted(fn () => Passport::tokensCan([
            'mcp:use' => 'Use the onsplan MCP server',
            'board:identity' => 'Sign in to the onsplan module-requests board',
            'admin' => 'Read onsplan platform data in admin.onsplan.nl',
        ] + McpTools::scopes()));

        // First-party board clients skip consent for `board:identity` (see App\Models\OAuthClient).
        Passport::useClientModel(OAuthClient::class);

        // Core MCP tools: always available to any connected token (no module scope needed).
        McpTools::register(McpTools::CORE, 'onsplan', [GetFamily::class, GetToday::class]);

        Passport::authorizationView(fn (array $p) => Inertia::render('oauth/authorize', $this->consentProps($p)));

        // 120 MCP calls per minute per token (one assistant turn can fan out into several tool calls).
        RateLimiter::for('mcp', fn (Request $request) => Limit::perMinute(120)->by(
            $request->user()?->token()?->oauth_access_token_id ?? $request->ip()
        ));
    }

    /**
     * Props for the OAuth consent page: the client, and for each of the user's families the MCP modules
     * enabled there. The page posts a NATIVE form (not Inertia): approving redirects to the client's
     * callback (another site or a claude:// URL), which an XHR could not follow, hence the CSRF token.
     *
     * @param  array{client: Client, user: User, request: Request, authToken: string}  $p
     */
    private function consentProps(array $p): array
    {
        $registry = app(ModuleRegistry::class);
        $mcpModules = McpTools::modules();

        return [
            'client' => ['id' => (string) $p['client']->getKey(), 'name' => $p['client']->name],
            'families' => $p['user']->families()->orderBy('name')->get()->map(fn (Family $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'modules' => collect($mcpModules)->only($registry->enabledIds($f->id))
                    ->map(fn (string $name, string $id) => ['id' => $id, 'name' => $name])->values(),
            ])->values(),
            'activeFamilyId' => $p['request']->session()->get('active_family_id'),
            'authToken' => $p['authToken'],
            'csrfToken' => csrf_token(),
        ];
    }
}
