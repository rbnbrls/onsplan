<?php

namespace App\Http\Middleware;

use App\Support\Analytics;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remembers, for a GUEST, who they were on onsplan.nl and where they came from, until they sign up
 * (RegisteredUserController::adoptAnalytics moves it onto the new user, then clears it).
 *
 *   - fw_vid: onsplan.nl's visitor UUID (its Umami identify id). Adopted as the user's analytics_id so
 *     marketing-site visits and in-app use are one person in Umami. First one wins.
 *   - first touch: fw_src/fw_med/fw_cmp (the .org's own first touch, so it beats anything seen here), else
 *     utm_* + the Referer, by the same rules as Folio's first-touch.ts: a real source is sticky, a stored
 *     "direct" is only the weak fallback and a later real source upgrades it, and a same-origin referrer
 *     (an internal hop) counts as direct so it never masks a real campaign.
 *
 * Session only, no DB: cheap enough to run on every guest web request. GET only, since a landing is a
 * GET (and a POST, like Apple's cross-site callback, has nothing to add). Off without Umami configured.
 */
class CaptureFirstTouch
{
    // [host regex, source, medium], as in Folio. An unknown host stays as its bare hostname (medium referral).
    private const HOSTS = [
        ['/(^|\.)google\./', 'google', 'organic'],
        ['/(^|\.)bing\./', 'bing', 'organic'],
        ['/(^|\.)duckduckgo\./', 'duckduckgo', 'organic'],
        ['/(^|\.)ecosia\./', 'ecosia', 'organic'],
        ['/(^|\.)yandex\./', 'yandex', 'organic'],
        ['/(^|\.)yahoo\./', 'yahoo', 'organic'],
        ['/(^|\.)t\.co$|(^|\.)twitter\.|(^|\.)x\.com$/', 'twitter', 'social'],
        ['/(^|\.)reddit\./', 'reddit', 'social'],
        ['/(^|\.)news\.ycombinator\.com$/', 'hackernews', 'social'],
        ['/(^|\.)linkedin\./', 'linkedin', 'social'],
        ['/(^|\.)facebook\.|(^|\.)fb\.com$/', 'facebook', 'social'],
        ['/(^|\.)instagram\./', 'instagram', 'social'],
        ['/(^|\.)threads\.net$/', 'threads', 'social'],
        ['/(^|\.)bsky\.app$/', 'bluesky', 'social'],
        ['/(^|\.)mastodon\.|(^|\.)mas\.to$/', 'mastodon', 'social'],
        ['/(^|\.)youtube\.|(^|\.)youtu\.be$/', 'youtube', 'social'],
        ['/(^|\.)tiktok\./', 'tiktok', 'social'],
        ['/(^|\.)substack\./', 'substack', 'referral'],
        ['/(^|\.)medium\./', 'medium', 'referral'],
        ['/(^|\.)producthunt\./', 'producthunt', 'referral'],
        ['/(^|\.)github\./', 'github', 'referral'],
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') && Analytics::enabled() && $request->hasSession() && ! $request->user()) {
            $this->capture($request);
        }

        return $next($request);
    }

    private function capture(Request $request): void
    {
        $session = $request->session();

        $visitor = $request->query('fw_vid');
        if (is_string($visitor) && Str::isUuid($visitor) && ! $session->has('analytics.visitor_id')) {
            $session->put('analytics.visitor_id', Str::lower($visitor));
        }

        $stored = $session->get('analytics.first_touch');
        if (is_array($stored) && ($stored['source'] ?? 'direct') !== 'direct') {
            return; // a real first touch is sticky
        }
        $current = self::currentVisit($request);
        if (! is_array($stored) || $current['source'] !== 'direct') {
            $session->put('analytics.first_touch', $current);
        }
    }

    /** @return array{source: string, medium: string, campaign: ?string, referrer: ?string, landing_path: string, at: string} */
    private static function currentVisit(Request $request): array
    {
        $referrer = self::clean($request->headers->get('referer'), 255);
        $sameOrigin = $referrer && Str::lower((string) parse_url($referrer, PHP_URL_HOST)) === Str::lower($request->getHost());

        if ($source = self::clean($request->query('fw_src'))) {
            [$medium, $campaign] = [self::clean($request->query('fw_med')) ?? 'unknown', self::clean($request->query('fw_cmp'))];
        } elseif ($source = self::clean($request->query('utm_source'))) {
            [$medium, $campaign] = [self::clean($request->query('utm_medium')) ?? 'unknown', self::clean($request->query('utm_campaign'))];
        } else {
            [$source, $medium] = $sameOrigin ? ['direct', 'none'] : self::fromReferrer($referrer);
            $campaign = self::clean($request->query('utm_campaign'));
        }

        return [
            'source' => $source,
            'medium' => $medium,
            'campaign' => $campaign,
            'referrer' => $sameOrigin ? null : $referrer,
            // The route's URI template (invite/{token}, reset-password/{token}), not the real path: a landing
            // URL can carry a secret token or an email address, which must never end up in analytics.
            'landing_path' => '/'.ltrim($request->route()?->uri() ?? $request->path(), '/'),
            'at' => now()->toIso8601ZuluString(),
        ];
    }

    /** @return array{0: string, 1: string} [source, medium] */
    private static function fromReferrer(?string $referrer): array
    {
        $host = $referrer ? Str::lower((string) parse_url($referrer, PHP_URL_HOST)) : '';
        if ($host === '') {
            return ['direct', 'none'];
        }
        foreach (self::HOSTS as [$pattern, $source, $medium]) {
            if (preg_match($pattern, $host)) {
                return [$source, $medium];
            }
        }

        return [$host, 'referral'];
    }

    // A trimmed, length-capped string from a query/header value; null when absent, empty or not a string.
    private static function clean(mixed $value, int $max = 100): ?string
    {
        $value = is_string($value) ? mb_substr(trim($value), 0, $max) : '';

        return $value === '' ? null : $value;
    }
}
