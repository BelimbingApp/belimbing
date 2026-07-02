<?php

namespace Tests\Support;

use App\Modules\Core\Geonames\Models\Admin1;
use App\Modules\Core\Geonames\Models\Country;
use App\Modules\Core\Geonames\Models\Postcode;

/**
 * Seed minimal GeoNames rows for pagination/list UI tests.
 */
final class GeonamesSeeder
{
    public static function countries(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $iso = sprintf('C%02d', $i);

            Country::query()->create([
                'iso' => $iso,
                'iso3' => $iso.'A',
                'iso_numeric' => sprintf('%03d', 900 + $i),
                'country' => "Country $i",
                'continent' => 'EU',
                'currency_code' => 'EUR',
                'currency_name' => 'Euro',
            ]);
        }
    }

    public static function admin1(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            Admin1::query()->create([
                'code' => "C$i.A$i",
                'name' => "Division $i",
            ]);
        }
    }

    public static function postcodes(int $count): void
    {
        Country::query()->create([
            'iso' => 'CC',
            'iso3' => 'CCA',
            'iso_numeric' => '999',
            'country' => 'Test Country',
            'continent' => 'EU',
            'currency_code' => 'EUR',
            'currency_name' => 'Euro',
        ]);

        for ($i = 0; $i < $count; $i++) {
            Postcode::query()->create([
                'country_iso' => 'CC',
                'postcode' => sprintf('%05d', $i),
                'place_name' => "Place $i",
            ]);
        }
    }
}
