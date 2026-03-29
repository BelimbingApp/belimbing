<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>


namespace App\Modules\Core\Geonames\Database\Seeders;

use App\Modules\Core\Geonames\Database\Seeders\Concerns\DownloadsGeonamesFile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ZipArchive;

class CitySeeder extends Seeder
{
    use DownloadsGeonamesFile;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $url = 'https://download.geonames.org/export/dump/cities15000.zip';
        $zipPath = $this->downloadGeonamesFile($url, 'cities15000.zip', $this->command);

        if (! $zipPath) {
            return;
        }

        $filePath = $this->extractCitiesFile($zipPath);

        if (! $filePath) {
            return;
        }

        $this->command?->info('Parsing cities15000.txt...');
        $content = File::get($filePath);
        $lines = explode("\n", $content);

        $cities = [];
        $skipped = 0;

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line)) {
                continue;
            }

            $parts = explode("\t", $line);

            if (count($parts) < 19) {
                $skipped++;

                continue;
            }

            $cities[] = [
                'geoname_id' => $parts[0],
                'name' => $parts[1],
                'ascii_name' => $parts[2],
                'alternate_names' => $parts[3],
                'latitude' => $parts[4],
                'longitude' => $parts[5],
                'country_iso' => $parts[8],
                'admin1_code' => $parts[10],
                'population' => $parts[14],
                'timezone' => $parts[17],
                'modification_date' => $parts[18],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $this->command?->info('Inserting '.count($cities).' cities...');
        $chunks = array_chunk($cities, 500);

        foreach ($chunks as $chunk) {
            DB::table('geonames_cities')->upsert(
                $chunk,
                ['geoname_id'],
                ['name', 'ascii_name', 'alternate_names', 'latitude', 'longitude', 'country_iso', 'admin1_code', 'population', 'timezone', 'modification_date', 'updated_at'],
            );
        }

        $this->command?->info('Done. Skipped: '.$skipped);
    }

    /**
     * Extract the `cities15000.txt` payload from the downloaded zip.
     */
    protected function extractCitiesFile(string $zipPath): ?string
    {
        $extractPath = dirname($zipPath);
        $txtPath = $extractPath.'/cities15000.txt';

        if (File::exists($txtPath)) {
            $this->command?->info('Using cached cities15000.txt file.');

            return $txtPath;
        }

        $this->command?->info('Extracting cities15000.zip...');

        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            $this->command?->error('Failed to open cities15000.zip archive.');

            return null;
        }

        $entryName = 'cities15000.txt';
        $entryIndex = $zip->locateName($entryName);

        if ($entryIndex === false) {
            $this->command?->error('File cities15000.txt not found in cities15000.zip.');
            $zip->close();

            return null;
        }

        $zip->extractTo($extractPath, $entryName); // NOSONAR — archive from trusted Geonames source, extracted within admin-only seeder to a controlled temp path
        $zip->close();

        $this->command?->info('Extracted cities15000.txt successfully.');

        return $txtPath;
    }
}
