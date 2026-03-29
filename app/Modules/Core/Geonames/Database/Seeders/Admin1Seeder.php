<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>


namespace App\Modules\Core\Geonames\Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use App\Modules\Core\Geonames\Database\Seeders\Concerns\DownloadsGeonamesFile;

class Admin1Seeder extends Seeder
{
    use DownloadsGeonamesFile;

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $url = 'https://download.geonames.org/export/dump/admin1CodesASCII.txt';
        $filePath = $this->downloadGeonamesFile($url, 'admin1CodesASCII.txt', $this->command);

        if (! $filePath) {
            return;
        }

        $records = $this->parseFile($filePath);

        if (empty($records)) {
            $this->command?->info('No admin1 records to import.');
            return;
        }

        $this->command?->info('Upserting '.count($records).' admin1 records...');

        $updateColumns = array_values(array_diff(
            array_keys($records[0]),
            ['code', 'name', 'created_at'],
        ));

        foreach (array_chunk($records, 100) as $chunk) {
            DB::table('geonames_admin1')->upsert(
                $chunk,
                ['code'],
                $updateColumns,
            );
        }

        $this->command?->info('Imported '.count($records).' admin1 records.');
    }

    /**
     * Parse the admin1 codes file and return importable records.
     *
     * @param  string  $filePath  Path to the admin1CodesASCII.txt file
     * @return array<int, array<string, mixed>>
     */
    protected function parseFile(string $filePath): array
    {
        $content = File::get($filePath);
        $lines = explode("\n", $content);
        $records = [];

        // Expected columns: code, name, alt_name, geoNameId
        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $parts = explode("\t", $line);
            if (count($parts) < 4) {
                continue;
            }

            $records[] = [
                'code' => $parts[0],
                'name' => $parts[1],
                'alt_name' => $parts[2],
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $records;
    }
}
