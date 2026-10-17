<?php

namespace App\Http\Middleware;

use App\Models\Family;
use App\Support\Billing\Billing;
use App\Support\Mcp\McpTools;
use App\Support\Tenancy\CurrentFamily;
use App\Support\Translations;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

// Runs after auth:api on /mcp. A token is bound to exactly one family through its `family:<id>` scope
// (picked on the consent screen); this sets that family as the request's tenant so every
// BelongsToFamily query behaves as in the web UI. No session is involved.
class McpFamilyContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        // Only a real bearer token has oauth_scopes (a cookie TransientToken has none -> refused).
        $familyId = McpTools::familyFromScopes((array) ($user?->token()?->oauth_scopes ?? []));
        if (! $familyId) {
            return response()->json(['error' => 'This token is not bound to a family. Reconnect the app.'], 403);
        }

        // Leaving a family kills its connectors: membership is re-checked on every request.
        if (! $user->families()->whereKey($familyId)->exists()) {
            return response()->json(['error' => 'You are no longer a member of this family. Reconnect the app.'], 403);
        }

        // Same paywall as the web app (RequireFamily): connectors stop working when the subscription lapses.
        if (Billing::enabled() && ! Billing::hasAccess(Family::findOrFail($familyId))) {
            return response()->json(['error' => 'This family has no active onsplan subscription. Subscribe in Settings > Billing.'], 402);
        }

        CurrentFamily::set($familyId);

        // /mcp is outside the web group, so SetLocale never runs: without this, server strings built with
        // Translations::get (birthday titles, "day N of M") came out in English for everyone. Same rule as
        // SetLocale: the person's language, else the app default (families have no language of their own).
        // Safe under Octane: its FlushLocaleState listener resets the locale before every request.
        $locale = $user->locale;
        if ($locale && Translations::supported($locale)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
