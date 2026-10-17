<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use App\Support\Modules\ModuleRegistry;
use Modules\Spending\Currencies;
use Modules\Spending\Fx;
use Modules\Spending\SpendSetting;

use function Pest\Laravel\actingAs;

// Every currency (Currencies::ALL), the location-based default for a new family, and the one-fetch rate
// table behind the display-currency switcher.

beforeEach(fn () => Cache::flush());

it('offers every convertible currency and never the old list order as a default', function () {
    expect(Currencies::ALL)->toContain('EUR', 'RON', 'USD', 'GBP', 'JPY', 'MDL', 'INR', 'BRL')
        ->not->toContain('CNH', 'XDR', 'HRK', 'SLL')
        ->and(count(Currencies::ALL))->toBe(count(array_unique(Currencies::ALL)))
        ->and(Currencies::FALLBACK)->toBe('EUR');
});

it('maps a country to its currency, and nothing for an unknown or currency-less one', function () {
    expect(Currencies::forCountry('RO'))->toBe('RON')
        ->and(Currencies::forCountry('de'))->toBe('EUR')
        ->and(Currencies::forCountry('JP'))->toBe('JPY')
        ->and(Currencies::forCountry('AQ'))->toBeNull()   // Antarctica: ICU says XXX
        ->and(Currencies::forCountry(null))->toBeNull();
});

it('sets a new family\'s default currency from where its creator signs up', function () {
    test()->artisan('modules:migrate')->assertExitCode(0);
    $user = User::factory()->create();

    actingAs($user)->post('/onboarding', ['name' => 'Popescu', 'color' => '#2D5E3A', 'emoji' => '🏡'], ['CF-IPCountry' => 'RO'])
        ->assertRedirect();

    expect(SpendSetting::defaultCurrency($user->fresh()->families()->first()->id))->toBe('RON');
});

it('leaves the default unset without a usable country (local, Tor), so EUR applies', function () {
    test()->artisan('modules:migrate')->assertExitCode(0);
    foreach ([[], ['CF-IPCountry' => 'T1']] as $i => $headers) {
        $user = User::factory()->create();
        actingAs($user)->post('/onboarding', ['name' => "Fam {$i}", 'color' => '#2D5E3A', 'emoji' => '🏡'], $headers)->assertRedirect();
        expect(SpendSetting::defaultCurrency($user->fresh()->families()->first()->id))->toBeNull();
    }
});

it('accepts a list in any supported currency', function () {
    test()->artisan('modules:migrate')->assertExitCode(0);
    $user = User::factory()->create();
    actingAs($user)->post('/onboarding', ['name' => 'Tanaka', 'color' => '#2D5E3A', 'emoji' => '🏡']);
    app(ModuleRegistry::class)->enable('spending', $user->fresh()->families()->first()->id);
    Http::fake(['*' => Http::response(['rates' => []])]);

    actingAs($user)->get('/m/spending')->assertInertia(fn ($page) => $page
        ->where('defaultCurrency', 'EUR') // nothing set: the fallback, not the alphabetical first (AED)
        ->where('currencies', Currencies::ALL));
    actingAs($user)->post('/m/spending/events', ['name' => 'Tokyo trip', 'currency' => 'JPY'])->assertSessionHasNoErrors();
    actingAs($user)->post('/m/spending/events', ['name' => 'Nope', 'currency' => 'XDR'])->assertSessionHasErrors('currency');
});

it('builds the display rates from ONE table per base, ECB winning, cached', function () {
    Http::fake([
        'open.er-api.com/*' => Http::response(['rates' => ['EUR' => 1.0, 'RON' => 4.9, 'MDL' => 19.5, 'JPY' => 160.0]]),
        'api.frankfurter.dev/*' => Http::response(['rates' => ['RON' => 4.97, 'JPY' => 161.2]]),
    ]);

    $rates = Fx::ratesFrom('EUR', Currencies::ALL);
    Fx::ratesFrom('EUR', Currencies::ALL);

    expect($rates)->toMatchArray(['EUR' => 1.0, 'RON' => 4.97, 'JPY' => 161.2, 'MDL' => 19.5])
        ->and($rates)->not->toHaveKey('XDR');
    Http::assertSentCount(2); // one call per source, then the cache
});

it('does not cache an empty table when both sources are down', function () {
    Http::fake(['*' => Http::response('', 500)]);
    expect(Fx::ratesFrom('EUR', ['EUR', 'RON']))->toBe(['EUR' => 1.0])
        ->and(Cache::has('fx:table:EUR'))->toBeFalse();
});
