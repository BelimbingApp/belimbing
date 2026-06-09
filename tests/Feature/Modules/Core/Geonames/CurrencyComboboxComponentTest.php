<?php

use App\Modules\Core\Address\Models\Address;
use App\Modules\Core\Geonames\Models\Country;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Blade;

it('prioritizes the current company currency, then usd and eur, in the currency combobox', function (): void {
    $user = createAdminUser();
    Auth::login($user);

    Country::query()->create([
        'iso' => 'MY',
        'iso3' => 'MYS',
        'iso_numeric' => '458',
        'country' => 'Malaysia',
        'continent' => 'AS',
        'currency_code' => 'MYR',
        'currency_name' => 'Ringgit',
    ]);

    Country::query()->create([
        'iso' => 'US',
        'iso3' => 'USA',
        'iso_numeric' => '840',
        'country' => 'United States',
        'continent' => 'NA',
        'currency_code' => 'USD',
        'currency_name' => 'Dollar',
    ]);

    Country::query()->create([
        'iso' => 'DE',
        'iso3' => 'DEU',
        'iso_numeric' => '276',
        'country' => 'Germany',
        'continent' => 'EU',
        'currency_code' => 'EUR',
        'currency_name' => 'Euro',
    ]);

    Country::query()->create([
        'iso' => 'AU',
        'iso3' => 'AUS',
        'iso_numeric' => '036',
        'country' => 'Australia',
        'continent' => 'OC',
        'currency_code' => 'AUD',
        'currency_name' => 'Dollar',
    ]);

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