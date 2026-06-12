<?php

use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;

function createCurrencyCountry(string $iso, string $iso3, string $isoNumeric, string $country, string $continent, string $currencyCode, string $currencyName): void
{
    Country::query()->create([
        'iso' => $iso,
        'iso3' => $iso3,
        'iso_numeric' => $isoNumeric,
        'country' => $country,
        'continent' => $continent,
        'currency_code' => $currencyCode,
        'currency_name' => $currencyName,
    ]);
}

it('prioritizes the current company currency, then usd and eur, in the currency combobox', function (): void {
    $user = createAdminUser();
    Auth::login($user);

    createCurrencyCountry('MY', 'MYS', '458', 'Malaysia', 'AS', 'MYR', 'Ringgit');
    createCurrencyCountry('US', 'USA', '840', 'United States', 'NA', 'USD', 'Dollar');
    createCurrencyCountry('DE', 'DEU', '276', 'Germany', 'EU', 'EUR', 'Euro');
    createCurrencyCountry('AU', 'AUS', '036', 'Australia', 'OC', 'AUD', 'Dollar');

    $address = Address::factory()->create(['country_iso' => 'MY']);

    $user->company->addresses()->attach($address->id, [
        'kind' => json_encode(['headquarters']),
        'is_primary' => true,
        'priority' => 0,
    ]);

    $html = html_entity_decode(Blade::render('<x-ui.currency-combobox label="Currency" />'));

    $myrPosition = strpos($html, 'Ringgit (MYR)');
    $usdPosition = strpos($html, 'Dollar (USD)');
    $eurPosition = strpos($html, 'Euro (EUR)');
    $audPosition = strpos($html, 'Dollar (AUD)');

    expect($myrPosition)->not->toBeFalse()
        ->and($usdPosition)->not->toBeFalse()
        ->and($eurPosition)->not->toBeFalse()
        ->and($audPosition)->not->toBeFalse()
        ->and($myrPosition)->toBeLessThan($usdPosition)
        ->and($usdPosition)->toBeLessThan($eurPosition)
        ->and($eurPosition)->toBeLessThan($audPosition);
});
