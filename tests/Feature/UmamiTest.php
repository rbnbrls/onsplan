<?php

use function Pest\Laravel\get;

// The Umami tracker loads only when UMAMI_WEBSITE_ID is set (hosted onsplan.nl), never on self-hosted installs.

it('loads no tracker without a website id', function () {
    config(['services.umami.website_id' => null]);

    get('/login')->assertOk()->assertDontSee('data-website-id', false);
});

it('loads the tracker for the configured website and app host', function () {
    config(['services.umami.website_id' => 'site-123', 'app.url' => 'https://onsplan.nl']);

    get('/login')->assertOk()
        ->assertSee('src="https://analytics.dragonpie.co/script.js" data-website-id="site-123" data-domains="onsplan.nl"', false);
});
