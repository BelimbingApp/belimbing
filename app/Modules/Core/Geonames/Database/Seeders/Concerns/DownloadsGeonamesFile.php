<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\Geonames\Database\Seeders\Concerns;

use App\Modules\Core\Geonames\Services\GeonamesDownloader;
use Illuminate\Support\Facades\File;

trait DownloadsGeonamesFile
{
    /**
     * Download a file from geonames.org with ETag/TTL and error reporting.
     *
     * @param  string  $url
     * @param  string  $filename  (e.g. 'countryInfo.txt')
     * @param  \Illuminate\Console\Command|null  $command
     * @return string|null  Returns file path or null on failure
     */
    protected function downloadGeonamesFile(string $url, string $filename, $command = null): ?string
    {
        $downloadPath = storage_path('download/geonames');
        $filePath = $downloadPath.'/'.$filename;

        if (! File::exists($downloadPath)) {
            File::makeDirectory($downloadPath, 0755, true);
        }

        $downloader = app(GeonamesDownloader::class);
        $result = $downloader->download($url, $filePath);

        if (! $result['success']) {
            $command?->error('Failed to download '.$filename.': '.($result['status'] ?? 'unknown'));
            return null;
        }

        if ($result['cached']) {
            $command?->info('Using cached '.$filename.' file.');
        } else {
            $command?->info('Downloaded '.$filename.' successfully.');
        }

        return $filePath;
    }
}
