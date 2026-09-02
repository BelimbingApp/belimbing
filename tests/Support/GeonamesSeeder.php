<?php

namespace Tests\Support;

use App\Core\Geonames\Models\Admin1;
use App\Core\Geonames\Models\Country;
use App\Core\Geonames\Models\Postcode;

/**
 * Seed minimal GeoNames rows for pagination/list UI tests.
 */
final class GeonamesSeeder
{
    public static function countries(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $iso = self::iso($i);

            Country::query()->create([
                'iso' => $iso,
                'iso3' => 'X'.$iso,
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
                'code' => self::iso($i).'.A'.$i,
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

    /**
     * A distinct two-letter code for row $i.
     *
     * The real column is varchar(2) and PostgreSQL enforces that, while SQLite
     * ignores the declared length — so a wider fixture value silently passes on
     * one production driver and fails on the other. Keep this inside the
     * declared width so the fixture is honest on both.
     */
    private static function iso(int $index): string
    {
        return chr(ord('A') + intdiv($index, 26)).chr(ord('A') + $index % 26);
    }
}
