<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Geonames\Database\Seeders;

use App\Modules\Core\Geonames\Database\Seeders\Concerns\DownloadsGeonamesFile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class CountrySeeder extends Seeder
{
    use DownloadsGeonamesFile;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $url = 'https://download.geonames.org/export/dump/countryInfo.txt';
        $filePath = $this->downloadGeonamesFile($url, 'countryInfo.txt', $this->command);
        if (! $filePath) {
            return;
        }

        $this->command?->info('Parsing countryInfo.txt...');
        $content = File::get($filePath);
        $lines = explode("\n", $content);

        $countries = [];
        $skipped = 0;

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line) || str_starts_with($line, '#')) {
                $skipped++;

                continue;
            }

            // Columns: ISO, ISO3, ISO-Numeric, fips, Country, Capital, Area, Population, Continent, tld, CurrencyCode, CurrencyName, Phone, Postal Code Format, Postal Code Regex, Languages, geonameid, neighbours, EquivalentFipsCode
            $parts = explode("\t", $line);

            if (count($parts) < 17) {
                $skipped++;

                continue;
            }

            $countries[] = $this->parseCountryRow($parts);
        }

        $this->command?->info('Inserting '.count($countries).' countries...');
        $chunks = array_chunk($countries, 100);

        foreach ($chunks as $chunk) {
            DB::table('geonames_countries')->upsert(
                $chunk,
                ['iso'],
                ['iso3', 'iso_numeric', 'capital', 'area', 'population', 'continent', 'tld', 'currency_code', 'currency_name', 'phone', 'postal_code_format', 'postal_code_regex', 'languages', 'geoname_id', 'updated_at'],
            );
        }

        $this->command?->info(
            'Seeded '.
                count($countries).
                ' countries. Skipped '.
                $skipped.
                ' lines.',
        );
    }

    /**
     * Map a parsed TSV row to the geonames_countries column structure.
     *
     * Expects the 19-column countryInfo.txt format from geonames.org.
     *
     * @param  array<int, string>  $parts
     * @return array<string, mixed>
     */
    private function parseCountryRow(array $parts): array
    {
        return [
            'iso' => $parts[0] ?? null,
            'iso3' => $parts[1] ?? null,
            'iso_numeric' => $parts[2] ?? null,
            'country' => $parts[4] ?? null,
            'capital' => $parts[5] ?? null,
            'area' => ! empty($parts[6]) ? (float) $parts[6] : null,
            'population' => ! empty($parts[7]) ? (int) $parts[7] : 0,
            'continent' => $parts[8] ?? null,
            'tld' => $parts[9] ?? null,
            'currency_code' => $parts[10] ?? null,
            'currency_name' => $parts[11] ?? null,
            'phone' => $parts[12] ?? null,
            'postal_code_format' => $parts[13] ?? null,
            'postal_code_regex' => $parts[14] ?? null,
            'languages' => $parts[15] ?? null,
            'geoname_id' => ! empty($parts[16]) ? (int) $parts[16] : null,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }
}
