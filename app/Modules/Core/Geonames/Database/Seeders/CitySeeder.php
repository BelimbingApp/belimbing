<?php

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
        $handle = fopen($filePath, 'r');

        if ($handle === false) {
            $this->command?->error('Failed to open cities15000.txt.');

            return;
        }

        $chunk = [];
        $inserted = 0;
        $skipped = 0;
        $now = now();

        try {
            while (($line = fgets($handle)) !== false) {
                $line = trim($line);

                if (empty($line)) {
                    continue;
                }

                $parts = explode("\t", $line);

                if (count($parts) < 19) {
                    $skipped++;

                    continue;
                }

                $chunk[] = [
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
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (count($chunk) >= 500) {
                    $this->upsertCities($chunk);
                    $inserted += count($chunk);
                    $chunk = [];
                }
            }

            if ($chunk !== []) {
                $this->upsertCities($chunk);
                $inserted += count($chunk);
            }
        } finally {
            fclose($handle);
        }

        $this->command?->info('Inserted '.$inserted.' cities.');
        $this->command?->info('Done. Skipped: '.$skipped);
    }

    /**
     * @param  list<array<string, mixed>>  $cities
     */
    private function upsertCities(array $cities): void
    {
        DB::table('geonames_cities')->upsert(
            $cities,
            ['geoname_id'],
            ['name', 'ascii_name', 'alternate_names', 'latitude', 'longitude', 'country_iso', 'admin1_code', 'population', 'timezone', 'modification_date', 'updated_at'],
        );
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
