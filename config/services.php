<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Google Gemini, used only for receipt scanning. The whole feature is gated on `key` being set:
    // no key, no "Scan receipt" option (see ReceiptScanner::configured()). gemini-3-flash reads messy,
    // rotated, multilingual receipts reliably in testing while costing ~$0.001 per scan; self-hosters
    // can point `model` elsewhere.
    'gemini' => [
        'key' => env('GEMINI_API_KEY'),
        'model' => env('GEMINI_MODEL', 'gemini-3-flash-preview'),
    ],

    // TypeSafe (Jev), used only to guess a shopping item's aisle when it was added into the
    // catch-all "Other". Gated on `key`: no key, no guess (see AisleGuesser::configured()).
    'typesafe' => [
        'key' => env('TYPESAFE_API_KEY'),
        'model' => env('TYPESAFE_MODEL', 'jev-latest'),
    ],

    // Expense import: the "Install shortcut" link on its settings page. A shared iCloud link to the ready-made
    // "onsplan expense" Shortcut (made once on an iPhone, Share > Copy iCloud Link). Shortcuts can only be
    // installed from an iCloud link or a file signed on a Mac, so it can't ship in the repo; without it the
    // page shows the manual setup steps only.
    'expense_import' => [
        'shortcut_url' => env('EXPENSE_IMPORT_SHORTCUT_URL'),
    ],

    // Stripe, for the hosted onsplan.nl subscription. The WHOLE paywall (lock + Settings > Billing)
    // is gated on `key` (the publishable key) being set: self-hosters leave it empty and never see a
    // paywall. secret + webhook_secret are server-only; the two price IDs are the monthly/yearly plans
    // from the Stripe dashboard (Checkout is Stripe-hosted, so no plan logic lives in the app).
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'price_monthly' => env('STRIPE_PRICE_MONTHLY'),
        'price_yearly' => env('STRIPE_PRICE_YEARLY'),
    ],

    // Umami analytics (hosted onsplan.nl only). Empty = no tracker at all, so self-hosted installs never
    // report anywhere. The website id isn't secret; it ships in the page.
    'umami' => [
        'website_id' => env('UMAMI_WEBSITE_ID'),
        'script' => env('UMAMI_SCRIPT_URL', 'https://analytics.dragonpie.co/script.js'),
        // Where server-side events go (POST {host}/api/send, see App\Support\Analytics). Same Umami as the script.
        'host' => env('UMAMI_HOST', 'https://analytics.dragonpie.co'),
    ],

    // "Sign in with Google / Apple" (App\Support\Auth\SocialLogin). Each provider's button only shows when
    // ALL of its values are set, so self-hosted installs without keys see plain email + password only.
    // Google: an OAuth "Web application" client whose redirect URI is {APP_URL}/auth/google/callback.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
    ],

    // Apple: client_id is the Services ID (return URL {APP_URL}/auth/apple/callback; Apple refuses
    // localhost), plus the Team ID and a Sign in with Apple key (Key ID + the .p8 PEM contents). The PEM
    // may arrive on one line with literal \n escapes (that's how .do/apply.sh stores multi-line secrets);
    // SocialLogin turns those back into newlines.
    'apple' => [
        'client_id' => env('APPLE_CLIENT_ID'),
        'team_id' => env('APPLE_TEAM_ID'),
        'key_id' => env('APPLE_KEY_ID'),
        'private_key' => env('APPLE_PRIVATE_KEY'),
    ],

    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'tenant' => env('MICROSOFT_TENANT', 'common'),
        'redirect_uri' => env('MICROSOFT_REDIRECT_URI'),
        'webhook_url' => env('MICROSOFT_WEBHOOK_URL'),
    ],

];
