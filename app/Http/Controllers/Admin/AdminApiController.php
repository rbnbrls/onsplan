<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Family;
use App\Models\User;
use App\Support\Billing\Billing;
use App\Support\Engagement;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

// Platform data for admin.onsplan.nl: accounts and families, never what families store (no notes,
// lists, expenses...) and never secrets. Lists: 50 per page, newest first, literal search. Read-only
// except one write: granting / revoking a family's comped lifetime access (Billing::setLifetime).
class AdminApiController extends Controller
{
    private const PAGE = 50;

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json([
            'user_id' => $user->id, 'name' => $user->name, 'email' => $user->email,
            'email_verified' => $user->hasVerifiedEmail(), 'is_platform_admin' => (bool) $user->is_platform_admin,
            'families' => $user->families()->orderBy('name')->get(['families.id', 'families.name'])
                ->map(fn ($f) => ['id' => $f->id, 'name' => $f->name])->values(),
        ]);
    }

    public function stats(): JsonResponse
    {
        // The demo family and its members are seeded and reset nightly: not real usage.
        $real = fn () => User::query()->whereNotIn('id', fn ($q) => $q->select('family_user.user_id')->from('family_user')
            ->join('families', 'families.id', '=', 'family_user.family_id')->where('families.is_demo', true));

        return response()->json([
            'users' => $real()->count(),
            'verifiedUsers' => $real()->whereNotNull('email_verified_at')->count(),
            'families' => Family::query()->where('is_demo', false)->count(),
            'signups7d' => $real()->where('created_at', '>=', now()->subDays(7))->count(),
            'signups30d' => $real()->where('created_at', '>=', now()->subDays(30))->count(),
            // Same "paying" set as Billing::PAID (past_due keeps access while Stripe retries the card).
            'payingFamilies' => Family::query()->where('is_demo', false)->whereIn('subscription_status', ['active', 'trialing', 'past_due'])->count(),
            'pastDueFamilies' => Family::query()->where('is_demo', false)->where('subscription_status', 'past_due')->count(),
            // Comped "Lifetime (VIP)" families: have access without paying, so not in payingFamilies.
            'lifetimeFamilies' => Family::query()->where('is_demo', false)->where('access_override', Family::LIFETIME)->count(),
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $rows = self::search($this->userQuery(), self::q($request), ['users.name', 'users.email'])
            ->orderByDesc('users.created_at')->orderByDesc('users.id')
            ->offset((self::page($request) - 1) * self::PAGE)->limit(self::PAGE + 1)->get();

        return self::paged($rows, fn (User $u) => self::userItem($u));
    }

    public function user(int $id): JsonResponse
    {
        $user = $this->userQuery()->addSelect('users.locale', 'users.is_platform_admin', 'users.analytics_id', 'users.first_touch')->find($id);
        if (! $user) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json(self::userItem($user) + [
            'locale' => $user->locale,
            'isPlatformAdmin' => (bool) $user->is_platform_admin,
            // Detail only (not the list): the admin links the Umami id to its analytics profile and shows
            // where the person first came from ({source, medium, campaign, referrer, landing_path, at}).
            'analyticsId' => $user->analytics_id,
            'firstTouch' => $user->first_touch,
            'families' => $user->families()->orderBy('name')->get(['families.id', 'families.name'])
                ->map(fn ($f) => ['id' => $f->id, 'name' => $f->name, 'role' => $f->pivot->role])->values(),
        ]);
    }

    public function families(Request $request): JsonResponse
    {
        $rows = self::search($this->familyQuery(), self::q($request), ['families.name'])
            ->orderByDesc('families.created_at')->orderByDesc('families.id')
            ->offset((self::page($request) - 1) * self::PAGE)->limit(self::PAGE + 1)->get();
        // Every family's module activity for this page in ONE query (the progress bar), not one per row.
        $activity = DB::table('family_module_activity')->whereIn('family_id', $rows->pluck('id'))->get()->groupBy('family_id');

        return self::paged($rows, fn (Family $f) => self::familyItem($f, $activity->get($f->id, collect())));
    }

    // The admin toggle: PUT grants comped lifetime access, DELETE revokes it. Idempotent; answers with
    // the family as `family()` does. Stripe never touches the flag, so a webhook can't undo a grant.
    public function grantLifetime(Request $request, int $id): JsonResponse
    {
        return $this->setLifetime($request, $id, true);
    }

    public function revokeLifetime(Request $request, int $id): JsonResponse
    {
        return $this->setLifetime($request, $id, false);
    }

    private function setLifetime(Request $request, int $id, bool $on): JsonResponse
    {
        $family = Family::find($id);
        if (! $family) {
            return response()->json(['error' => 'not_found'], 404);
        }
        Billing::setLifetime($family, $on, 'admin:'.$request->user()->id);

        return $this->family($id);
    }

    public function family(int $id): JsonResponse
    {
        $family = $this->familyQuery()->find($id);
        if (! $family) {
            return response()->json(['error' => 'not_found'], 404);
        }

        return response()->json(self::familyItem($family, null) + [
            'members' => $family->members()->orderBy('name')->get(['users.id', 'users.name', 'users.email'])
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'email' => $m->email, 'role' => $m->pivot->role,
                    'joinedAt' => self::iso($m->pivot->created_at)])->values(),
        ]);
    }

    private function userQuery(): Builder
    {
        // Last activity = users.last_seen_at (web + MCP, see TrackLastSeen), falling back to the newest web
        // session for activity from before that column existed. One correlated subquery per row.
        return User::query()
            ->select('users.id', 'users.name', 'users.email', 'users.email_verified_at', 'users.created_at', 'users.last_seen_at', 'users.last_seen_via', 'users.country')
            ->selectSub(DB::table('sessions')->selectRaw('max(last_activity)')->whereColumn('sessions.user_id', 'users.id'), 'last_active')
            ->withCount('families');
    }

    private function familyQuery(): Builder
    {
        return Family::query()
            ->leftJoin('users as owners', 'owners.id', '=', 'families.owner_id')
            ->select('families.id', 'families.name', 'families.created_at', 'families.is_demo', 'families.subscription_status', 'families.stripe_customer_id', 'families.access_override', 'families.engaged_at', 'owners.name as owner_name')
            ->withCount('members');
    }

    private static function userItem(User $u): array
    {
        return [
            'id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'verified' => $u->email_verified_at !== null,
            'createdAt' => self::iso($u->created_at),
            ...self::lastActive($u),
            'familyCount' => (int) $u->families_count,
            'country' => $u->country, // ISO alpha-2 from Cloudflare, null until their first web visit since it was added
        ];
    }

    /** @return array{lastActiveAt: ?string, lastActiveVia: ?string} the newer of last_seen_at and the newest web session */
    private static function lastActive(User $u): array
    {
        $session = $u->last_active ? Carbon::createFromTimestamp((int) $u->last_active) : null;
        $seen = $u->last_seen_at;
        if ($seen && (! $session || $seen->gte($session))) {
            return ['lastActiveAt' => self::iso($seen), 'lastActiveVia' => $u->last_seen_via];
        }

        return ['lastActiveAt' => self::iso($session), 'lastActiveVia' => $session ? 'web' : null];
    }

    /** @param  iterable<object>|null  $activity  preloaded family_module_activity rows (null = load them) */
    private static function familyItem(Family $f, ?iterable $activity): array
    {
        return [
            'id' => $f->id, 'name' => $f->name, 'ownerName' => $f->owner_name, 'memberCount' => (int) $f->members_count,
            'createdAt' => self::iso($f->created_at), 'isDemo' => (bool) $f->is_demo,
            // The Stripe customer id isn't a secret (it only works with our Stripe key); the admin links to it.
            'subscriptionStatus' => $f->subscription_status, 'stripeCustomerId' => $f->stripe_customer_id,
            'lifetime' => $f->access_override === Family::LIFETIME, // comped access (admin toggle below)
            // Free-period progress: modules used so far (the bar's segments) and when the paywall shows.
            'engagement' => Engagement::summary($f, $activity),
            'paying' => Billing::isPaid($f),
        ];
    }

    // LIKE with % _ \ escaped, so the search box is literal; portable across SQLite (tests) and Postgres.
    private static function search(Builder $query, string $q, array $columns): Builder
    {
        if ($q === '') {
            return $query;
        }
        $like = '%'.addcslashes(mb_strtolower($q), '\\%_').'%';

        return $query->where(function (Builder $w) use ($columns, $like) {
            foreach ($columns as $column) {
                $w->orWhereRaw("lower({$column}) like ? escape '\\'", [$like]);
            }
        });
    }

    private static function q(Request $request): string
    {
        return mb_substr(trim((string) $request->query('q', '')), 0, 80);
    }

    // Clamped so a silly page number can't become a huge OFFSET.
    private static function page(Request $request): int
    {
        return max(1, min(10000, (int) $request->query('page', 1)));
    }

    private static function paged($rows, callable $map): JsonResponse
    {
        return response()->json(['items' => $rows->take(self::PAGE)->map($map)->values(), 'hasMore' => $rows->count() > self::PAGE]);
    }

    private static function iso($date): ?string
    {
        return $date ? Carbon::parse($date)->utc()->toIso8601ZuluString('millisecond') : null;
    }
}
