<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Server-side Umami events, for things that happen without a page view the browser tracker could see
 * (sign-up redirects, Stripe webhooks, assistant tool calls over MCP).
 *
 * Everything is gated on enabled() (UMAMI_WEBSITE_ID set), exactly like the <script> tag in app.blade.php:
 * a self-hosted install never reports anywhere. The person is identified ONLY by users.analytics_id (a
 * random UUID); event data is small and never personal (no names, emails or content).
 */
class Analytics
{
    // Umami silently drops requests without a User-Agent (it filters bots on it).
    private const USER_AGENT = 'onsplan/1.0 (+https://onsplan.nl)';

    public static function enabled(): bool
    {
        return filled(config('services.umami.website_id'));
    }

    /**
     * Queue an event for $user, sent AFTER the response so it never slows the request down.
     * app()->terminating() instead of a queued job: production runs no queue worker (only the scheduler),
     * so a queued job would never be sent. Under Octane the callback lives on the per-request sandbox app.
     *
     * @param  array<string, string|int>  $data
     * @param  string  $path  the "page" Umami files the event under, e.g. '/register', '/mcp'
     */
    public static function track(?User $user, string $event, array $data = [], string $path = '/'): void
    {
        if (! self::enabled() || ! $user?->analytics_id) {
            return;
        }

        $id = $user->analytics_id;
        // The app's terminating callbacks aren't cleared after they run, so where one app instance serves
        // several requests (the test suite) a later terminate() would replay this; send it only once.
        $sent = false;
        app()->terminating(function () use (&$sent, $id, $event, $data, $path) {
            if (! $sent) {
                $sent = true;
                self::send($id, $event, $data, $path);
            }
        });
    }

    /**
     * A funnel step for the BROWSER tracker to send on the next page it renders (shared as `analyticsEvents`
     * by HandleInertiaRequests, sent by app.tsx). For steps that end in a redirect the browser never
     * "sees" (demo entry, sign-up, Stripe's return), which a server-side track() can't put in the visitor's
     * Umami session: Umami keys sessions on IP + user agent, so only the browser's own events land in the
     * same visit as their onsplan.nl page views (docs/analytics.md). Kept in the session (not flashed)
     * and pulled when a page renders, so it survives any number of redirect hops in between.
     *
     * @param  array<string, string|int>  $data
     */
    public static function queue(string $event, array $data = []): void
    {
        if (self::enabled() && request()->hasSession()) {
            // The id lets the browser drop a repeat (an Inertia history visit replays the old page's props).
            request()->session()->push('analytics_events', ['id' => (string) Str::uuid(), 'name' => $event, 'data' => $data ?: null]);
        }
    }

    /**
     * POST one event to Umami's collect API. Never throws: analytics must not break what it measures, so
     * a slow or broken Umami costs at most the 2s timeout, after the response has gone out.
     *
     * @param  array<string, string|int>  $data
     */
    public static function send(string $analyticsId, string $event, array $data = [], string $path = '/'): void
    {
        if (! self::enabled()) {
            return;
        }

        try {
            Http::timeout(2)->withUserAgent(self::USER_AGENT)->post(rtrim((string) config('services.umami.host'), '/').'/api/send', [
                'type' => 'event',
                'payload' => [
                    'website' => config('services.umami.website_id'),
                    // The app's own host (onsplan.nl in production), the same one the script's data-domains allows.
                    'hostname' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: 'onsplan.nl',
                    'url' => $path,
                    'name' => $event,
                    'data' => (object) $data,
                    // Umami >= 2.13 takes this as the distinct id, the same one umami.identify() sets in the
                    // browser, so server events land on the same person as their page views.
                    'id' => $analyticsId,
                ],
            ]);
        } catch (\Throwable) {
            // Swallowed on purpose (see above). Not even report(): an Umami outage would flood the logs.
        }
    }
}
