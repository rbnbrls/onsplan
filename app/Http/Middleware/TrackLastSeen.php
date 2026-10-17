<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

// Stamps users.last_seen_at / last_seen_via ("web" or "mcp") for the admin's "last active". At most one
// write per user per 5 minutes: the already-loaded user model is checked first, so a busy session costs no
// extra query. A plain query-builder UPDATE, so it doesn't touch updated_at or fire model events.
//
// Web requests also keep users.country (the admin's "Country"): onsplan.nl is proxied by Cloudflare
// with IP geolocation on, so every request carries CF-IPCountry. MCP requests come from the assistant's
// servers (Anthropic / OpenAI), so their country says nothing about the person: web only.
class TrackLastSeen
{
    private const EVERY_SECONDS = 300;

    public function handle(Request $request, Closure $next, string $via = 'web'): Response
    {
        $response = $next($request);

        $user = $request->user();
        if (! $user) {
            return $response;
        }
        $seen = $user->last_seen_at;
        $country = $via === 'web' ? self::country($request) : null;
        $countryChanged = $country !== null && $country !== $user->country;
        if (! $seen || $seen->lt(now()->subSeconds(self::EVERY_SECONDS)) || $user->last_seen_via !== $via || $countryChanged) {
            DB::table('users')->where('id', $user->id)->update(
                ['last_seen_at' => now(), 'last_seen_via' => $via] + ($country !== null ? ['country' => $country] : []),
            );
        }

        return $response;
    }

    // Cloudflare's two-letter country, or null when there's none to trust: no header (local, self-hosted
    // without Cloudflare), XX (unknown) or T1 (Tor). Public: Spending's location-based default currency
    // reads the signup request the same way.
    public static function country(Request $request): ?string
    {
        $code = strtoupper((string) $request->header('CF-IPCountry'));

        return preg_match('/^[A-Z]{2}$/', $code) && ! in_array($code, ['XX', 'T1'], true) ? $code : null;
    }
}
