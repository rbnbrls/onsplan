<?php

namespace App\Support\Billing;

use App\Models\Family;
use App\Models\User;
use App\Support\Analytics;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The hosted-plan paywall. Everything is gated on enabled() (STRIPE_KEY set): an open-source install
 * without Stripe config never locks and never shows billing UI.
 *
 * ponytail: talks to Stripe's REST API through Laravel's Http client instead of pulling in stripe-php;
 * we only need three calls (checkout session, portal session, session lookup) + webhook signature
 * checking. Switch to stripe-php if billing grows (invoices, proration, coupons...).
 */
class Billing
{
    // Stripe keeps a subscription `past_due` while it retries a failed card (Smart Retries), so the family
    // keeps access during that grace; it flips to unpaid/canceled when retries run out, which locks.
    private const PAID = ['active', 'trialing', 'past_due'];

    // No paywall right after signup (23 checkouts in the first week, 2 payments): an unpaid family keeps
    // full access until it has added items in Engagement::MODULES_NEEDED modules AND is this old. That free
    // use IS the trial, so checkout has no Stripe trial (the 3-day one from #224 was dropped for this).
    // 'trialing' stays in PAID for any subscription that started on that trial.
    public const GRACE_HOURS = 24;

    public static function enabled(): bool
    {
        return filled(config('services.stripe.key'));
    }

    /** May this family use the app? Paid (or comped, demo, self-hosted), or not yet due for the paywall. */
    public static function hasAccess(Family $family): bool
    {
        return self::isPaid($family) || ! self::paywallDue($family);
    }

    /**
     * Paying or not needing to: the public demo always opens, self-hosted installs never lock, and a comped
     * family (lifetime VIP) has access whatever Stripe says (the Stripe paths never write access_override).
     * The Billing page shows the plan card for these and the plans for everyone else.
     */
    public static function isPaid(Family $family): bool
    {
        return ! self::enabled() || $family->is_demo || self::isLifetime($family)
            || in_array($family->subscription_status, self::PAID, true);
    }

    /**
     * The free period is over: the family used enough modules (engaged_at, see Engagement) and is at least
     * GRACE_HOURS old. Both are on the row already, so the per-request gate costs no extra query.
     */
    public static function paywallDue(Family $family): bool
    {
        return $family->engaged_at !== null && $family->created_at->lte(now()->subHours(self::GRACE_HOURS));
    }

    public static function isLifetime(Family $family): bool
    {
        return $family->access_override === Family::LIFETIME;
    }

    /**
     * Grant or revoke comped lifetime access. The one write path for the flag, shared by the admin API
     * (admin.onsplan.nl's toggle) and `php artisan family:lifetime`, and logged for the audit trail.
     */
    public static function setLifetime(Family $family, bool $on, string $by): void
    {
        $family->forceFill(['access_override' => $on ? Family::LIFETIME : null])->save();
        Log::info('billing: lifetime access '.($on ? 'granted' : 'revoked'), ['family_id' => $family->id, 'by' => $by]);
    }

    /** Stripe-hosted Checkout for one of the two plans. Returns the URL to redirect to. */
    public static function checkoutUrl(Family $family, string $email, string $plan): string
    {
        $price = config("services.stripe.price_{$plan}");
        if (! $price) {
            throw new RuntimeException('STRIPE_PRICE_'.strtoupper($plan).' is not set.');
        }

        $params = [
            'mode' => 'subscription',
            'line_items' => [['price' => $price, 'quantity' => 1]],
            'client_reference_id' => $family->id,
            // family_id rides on the SUBSCRIPTION so every later customer.subscription.* webhook maps
            // straight back to the family, with no lookup table and no ordering race vs. checkout events.
            'subscription_data' => ['metadata' => ['family_id' => $family->id]],
            'allow_promotion_codes' => 'true',
            'success_url' => route('billing.show').'?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => route('billing.show'),
        ];
        // Re-subscribing after a cancel reuses the family's customer, so the portal shows one history.
        $family->stripe_customer_id
            ? $params['customer'] = $family->stripe_customer_id
            : $params['customer_email'] = $email;

        return self::post('checkout/sessions', $params)['url'];
    }

    /** Stripe-hosted billing portal for the family's customer (change plan, card, cancel, invoices). */
    public static function portalUrl(Family $family): string
    {
        return self::post('billing_portal/sessions', [
            'customer' => $family->stripe_customer_id,
            'return_url' => route('billing.show'),
        ])['url'];
    }

    /**
     * Called on the Checkout success redirect: syncs the family right away so the person isn't bounced
     * back to the paywall while the webhook is still in flight. The webhook stays the source of truth.
     */
    public static function syncCheckoutSession(Family $family, string $sessionId): void
    {
        $session = self::request()->get("checkout/sessions/{$sessionId}", ['expand' => ['subscription']])->throw()->json();
        // Only accept a session that was opened for THIS family (the id comes from the query string).
        if ((string) ($session['client_reference_id'] ?? '') !== (string) $family->id || ! isset($session['subscription']['status'])) {
            return;
        }
        self::setStatus($family, $session['customer'], $session['subscription']);
    }

    /**
     * One-time backfill for families that subscribed before the plan columns existed: reads the customer's
     * newest subscription and stores it like a webhook would. Called by the Billing page only while the
     * plan is unknown, so it costs one Stripe call per such family, ever. Never throws: a Stripe hiccup
     * just leaves the card without the plan details until the next webhook.
     */
    public static function backfillPlan(Family $family): void
    {
        if ($family->subscription_plan || ! $family->stripe_customer_id) {
            return;
        }
        try {
            $subscription = self::request()->get('subscriptions', ['customer' => $family->stripe_customer_id, 'status' => 'all', 'limit' => 1])
                ->throw()->json('data.0');
            if ($subscription) {
                self::setStatus($family, $family->stripe_customer_id, $subscription);
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    /** Applies a customer.subscription.* event to the family named in its metadata. */
    public static function applySubscription(array $subscription): void
    {
        if ($family = Family::find($subscription['metadata']['family_id'] ?? null)) {
            self::setStatus($family, $subscription['customer'], $subscription);
        }
    }

    /**
     * The one place a family's subscription status changes (checkout redirect + webhook), so the analytics
     * events fire on the TRANSITION wherever it is first seen: the success redirect usually beats the
     * webhook, which then sees active -> active and stays quiet. Filed under the family owner.
     */
    private static function setStatus(Family $family, ?string $customer, array $subscription): void
    {
        $wasPaid = in_array($family->subscription_status, self::PAID, true);
        // Newer Stripe API versions moved current_period_end from the subscription onto its items.
        $periodEnd = $subscription['current_period_end'] ?? $subscription['items']['data'][0]['current_period_end'] ?? null;
        $family->forceFill([
            'stripe_customer_id' => $customer,
            'subscription_status' => $subscription['status'],
            'subscription_plan' => self::plan($subscription),
            'subscription_period_end' => $periodEnd ? Carbon::createFromTimestamp($periodEnd) : null,
            'subscription_canceling' => (bool) ($subscription['cancel_at_period_end'] ?? false),
        ])->save();
        $isPaid = in_array($subscription['status'], self::PAID, true);

        if ($wasPaid !== $isPaid) {
            $owner = User::find($family->owner_id);
            if ($isPaid) {
                Analytics::track($owner, 'subscription_started', ['plan' => self::plan($subscription)], '/billing');
            } else {
                // canceled / unpaid / incomplete_expired: access ended (a cancel at period end lands here later).
                Analytics::track($owner, 'subscription_canceled', [], '/billing');
            }
        }
    }

    /** 'monthly' | 'yearly' from the subscription's price, 'unknown' for a price we don't configure. */
    private static function plan(array $subscription): string
    {
        $price = $subscription['items']['data'][0]['price']['id'] ?? null;
        foreach (['monthly', 'yearly'] as $plan) {
            if ($price !== null && $price === config("services.stripe.price_{$plan}")) {
                return $plan;
            }
        }

        return 'unknown';
    }

    /**
     * Verifies a Stripe-Signature header (t=...,v1=...) against the raw payload: HMAC-SHA256 of
     * "{t}.{payload}" with the endpoint secret, within a 5 minute window to block replays.
     */
    public static function validSignature(string $payload, ?string $header): bool
    {
        $secret = config('services.stripe.webhook_secret');
        if (! $secret || ! $header) {
            return false;
        }
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            $k === 't' ? $t = $v : ($k === 'v1' ? $sigs[] = $v : null);
        }
        if (! ctype_digit((string) $t) || abs(time() - (int) $t) > 300) {
            return false;
        }
        $expected = hash_hmac('sha256', "{$t}.{$payload}", $secret);
        foreach ($sigs as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }

        return false;
    }

    private static function post(string $path, array $params): array
    {
        return self::request()->asForm()->post($path, $params)->throw()->json();
    }

    private static function request()
    {
        return Http::baseUrl('https://api.stripe.com/v1/')->withToken(config('services.stripe.secret'))->timeout(15);
    }
}
