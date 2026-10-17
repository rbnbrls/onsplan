<?php

use function Pest\Laravel\get;

// A stale link, a disabled module, or an expired session must not dump the user onto the stark
// default Symfony "404 NOT FOUND" page. Every error we render should carry the onsplan brand
// and a way back, and must NOT depend on the JS bundle (blade-only, so it survives a broken app).

it('renders a branded 404 with a way home instead of the default page', function () {
    $res = get('/a-page-that-does-not-exist')
        ->assertNotFound();

    // Brand + friendly copy + escape hatch, and the theme tokens so it matches the app.
    $res->assertSee('onsplan')
        ->assertSee('--primary:hsl(135.9, 35.3%, 27.3%)', false)
        ->assertSee('href="'.url('/').'"', false)
        ->assertDontSee('NOT FOUND'); // the default Symfony page we are replacing
});
