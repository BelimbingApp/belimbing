<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Geonames\Database\Seeders;

use App\Modules\Core\Geonames\Events\PostcodeImportProgress;
use App\Modules\Core\Geonames\Database\Seeders\Concerns\DownloadsGeonamesFile;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use ZipArchive;

class PostcodeSeeder extends Seeder
{
    use DownloadsGeonamesFile;

    /**
     * Broadcast a progress event, silently ignoring failures.
     */
    protected function broadcastProgress(string $status, string $message, int $current, int $total, ?string $iso = null): void
    {
        try {
            PostcodeImportProgress::dispatch($status, $message, $current, $total, $iso);
        } catch (\Throwable $e) {
            $this->command?->warn("Broadcast failed: {$e->getMessage()}");
        }
    }

    /**
     * Run the database seeds.
     *
     * Downloads and imports postcode data from geonames.org for the given
     * country ISO codes. When no codes are provided, re-imports countries
     * that already exist in the postcodes table.
     *
     * @param  ?array<int, string>  $countryCodes  ISO country codes to import
     * @return array<string, array<string, mixed>>
     */
    public function run(?array $countryCodes = null): array
    {
        if ($countryCodes === null) {
            $countryCodes = DB::table('geonames_postcodes')
                ->distinct()
                ->pluck('country_iso')
                ->all();
        }

        if (empty($countryCodes)) {
            $this->command?->info('No country codes provided and no existing postcode data found.');

            return [];
        }

        $total = count($countryCodes);
        $results = [];

        foreach ($countryCodes as $index => $iso) {
            $iso = strtoupper($iso);
            $current = $index + 1;

            try {
                $results[$iso] = $this->importCountry($iso, $current, $total);
            } catch (\Throwable $e) {
                $message = "Failed to import postcodes for {$iso}: {$e->getMessage()}";
                $this->command?->error($message);

                $this->broadcastProgress('failed', $message, $current, $total, $iso);

                $results[$iso] = [
                    'status' => 'failed',
                    'message' => $e->getMessage(),
                    'count' => 0,
                ];
            }
        }

        return $results;
    }

    /**
     * Import postcode data for a single country.
     *
     * @param  string  $iso  Country ISO code
     * @param  int  $current  Current country index (1-based)
     * @param  int  $total  Total number of countries to import
     * @return array<string, mixed>
     */
    protected function importCountry(string $iso, int $current, int $total): array
    {
        $this->broadcastProgress('downloading', "Downloading postcodes for {$iso}...", $current, $total, $iso);

        $filePath = $this->downloadFile($iso);

        if (! $filePath) {
            return [
                'status' => 'failed',
                'message' => 'Download failed',
                'count' => 0,
            ];
        }

        $this->broadcastProgress('importing', "Importing postcodes for {$iso}...", $current, $total, $iso);

        $records = $this->parseFile($filePath, $iso);

        if (empty($records)) {
            $message = "No postcode records found for {$iso}.";
            $this->command?->info($message);

            $this->broadcastProgress('completed', $message, $current, $total, $iso);

            return [
                'status' => 'completed',
                'message' => $message,
                'count' => 0,
            ];
        }

        DB::transaction(function () use ($iso, $records): void {
            DB::table('geonames_postcodes')
                ->where('country_iso', $iso)
                ->delete();

            foreach (array_chunk($records, 500) as $chunk) {
                DB::table('geonames_postcodes')->insert($chunk);
            }
        });

        $count = count($records);
        $message = "Imported {$count} postcodes for {$iso}.";
        $this->command?->info($message);

        $this->broadcastProgress('completed', $message, $current, $total, $iso);

        return [
            'status' => 'completed',
            'message' => $message,
            'count' => $count,
        ];
    }

    /**
     * Download and extract the postcode zip file for a country.
     *
     * Uses ETag when the server provides it (conditional GET); otherwise uses
     * a cached copy when the extracted txt is less than 7 days old. Keeps the
     * zip file so that ETag can be used on the next run.
     *
     * @param  string  $iso  Country ISO code
     */
    protected function downloadFile(string $iso): ?string
    {
        $url = 'https://download.geonames.org/export/zip/'.$iso.'.zip';
        $zipPath = $this->downloadGeonamesFile($url, $iso.'.zip', $this->command);

        if (! $zipPath) {
            return null;
        }

        $downloadPath = dirname($zipPath);
        $txtPath = $downloadPath.'/'.$iso.'.txt';

        if (File::exists($txtPath)) {
            $this->command?->info("Using cached {$iso}.txt file.");

            return $txtPath;
        }

        $this->command?->info("Extracting {$iso}.zip...");
        $extracted = $this->extractZip($zipPath, $downloadPath, $iso);

        if (! $extracted) {
            return null;
        }

        $this->command?->info("Extracted {$iso}.txt successfully.");
        return $txtPath;
    }

    /**
     * Extract the country txt file from a zip archive.
     *
     * @param  string  $zipPath  Path to the zip file
     * @param  string  $extractPath  Directory to extract into
     * @param  string  $iso  Country ISO code
     */
    protected function extractZip(string $zipPath, string $extractPath, string $iso): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            $this->command?->error("Failed to open {$iso}.zip archive.");

            return false;
        }

        $entryName = $iso.'.txt';
        $entryIndex = $zip->locateName($entryName);

        if ($entryIndex === false) {
            $this->command?->error("File {$entryName} not found in {$iso}.zip.");
            $zip->close();

            return false;
        }

        $zip->extractTo($extractPath, $entryName); // NOSONAR — archive from trusted Geonames source, extracted within admin-only seeder to a controlled temp path
        $zip->close();

        return true;
    }

    /**
     * Parse the tab-delimited postcode txt file and return importable records.
     *
     * @param  string  $filePath  Path to the extracted txt file
     * @param  string  $iso  Country ISO code
     * @return array<int, array<string, mixed>>
     */
    protected function parseFile(string $filePath, string $iso): array
    {
        $content = File::get($filePath);
        $lines = explode("\n", $content);
        $records = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if (empty($line) || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode("\t", $line);

            if (count($parts) < 3) {
                continue;
            }

            $records[] = [
                'country_iso' => $parts[0],
                'postcode' => $parts[1] ?? null,
                'place_name' => $parts[2] ?? null,
                'admin1Code' => $parts[4] ?? null,
                'admin_name1' => $parts[3] ?? null,
                'admin_code1' => $parts[4] ?? null,
                'admin_name2' => $parts[5] ?? null,
                'admin_code2' => $parts[6] ?? null,
                'admin_name3' => $parts[7] ?? null,
                'admin_code3' => $parts[8] ?? null,
                'latitude' => ! empty($parts[9] ?? null) ? (float) $parts[9] : null,
                'longitude' => ! empty($parts[10] ?? null) ? (float) $parts[10] : null,
                'accuracy' => ! empty($parts[11] ?? null) ? (int) $parts[11] : null,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        return $records;
    }
}
