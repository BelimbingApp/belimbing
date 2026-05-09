<?php
namespace App\Modules\Core\Geonames\Services;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

/**
 * Downloads files from geonames.org with ETag support and TTL fallback.
 *
 * When the server returns ETag, sends If-None-Match on subsequent requests;
 * 304 Not Modified skips re-download. When ETag is not available, uses
 * file modification time with a configurable TTL (default 7 days).
 */
class GeonamesDownloader
{
    /**
     * Default TTL in days when no ETag is available.
     */
    private const DEFAULT_TTL_DAYS = 7;

    /**
     * HTTP timeout in seconds for the request.
     */
    private const TIMEOUT_SECONDS = 300;

    /**
     * Download a file from the given URL, using ETag when available and TTL fallback.
     *
     * Writes the response body to $filePath and stores ETag in $filePath.etag when
     * a full download occurs. When the server returns 304 or when cached file is
     * within TTL (and no ETag exists), no write is performed.
     *
     * @param  string  $url  Full URL to download
     * @param  string  $filePath  Absolute path for the downloaded file (ETag stored in $filePath.etag)
     * @param  int  $ttlDays  Days to consider cached file fresh when no ETag is stored
     * @return array{success: bool, cached: bool, body: string|null, etag: string|null, status: int|null}
     */
    public function download(string $url, string $filePath, int $ttlDays = self::DEFAULT_TTL_DAYS): array
    {
        $etagPath = $filePath.'.etag';
        $fileExists = File::exists($filePath);
        $storedEtag = $this->readStoredEtag($etagPath);

        // We have file and ETag: conditional GET.
        if ($fileExists && $storedEtag !== null) {
            $result = $this->tryConditionalGet($url, $filePath, $etagPath, $storedEtag);
            if ($result !== null) {
                return $result;
            }
        }

        // File exists but no ETag: use TTL. If within TTL, skip download.
        if ($fileExists && $storedEtag === null) {
            $mtime = File::lastModified($filePath);
            if ($mtime >= now()->subDays($ttlDays)->timestamp) {
                return [
                    'success' => true,
                    'cached' => true,
                    'body' => null,
                    'etag' => null,
                    'status' => null,
                ];
            }
        }

        // Full GET: no file, or past TTL with no ETag.
        $response = Http::timeout(self::TIMEOUT_SECONDS)->get($url);
        $status = $response->status();

        if (! $response->successful()) {
            return [
                'success' => false,
                'cached' => false,
                'body' => null,
                'etag' => null,
                'status' => $status,
            ];
        }

        $body = $response->body();
        $etag = $response->header('ETag');
        File::put($filePath, $body);
        if ($etag !== null) {
            $this->writeEtag($etagPath, $etag);
        }

        return [
            'success' => true,
            'cached' => false,
            'body' => $body,
            'etag' => $etag,
            'status' => $status,
        ];
    }

    /**
     * Perform a conditional GET and resolve it to a final download result.
     *
     * Returns a result array on 304 (cached), 200 (downloaded), or 4xx (error).
     * Returns null when the response status does not match any handled case,
     * signalling the caller to fall through to a full GET.
     *
     * @param  string  $url  URL to request
     * @param  string  $filePath  Local path to write the downloaded body
     * @param  string  $etagPath  Path to the stored ETag file
     * @param  string  $storedEtag  Previously stored ETag value
     * @return array{success: bool, cached: bool, body: string|null, etag: string|null, status: int|null}|null
     */
    private function tryConditionalGet(string $url, string $filePath, string $etagPath, string $storedEtag): ?array
    {
        $result = $this->conditionalGet($url, $storedEtag);

        if ($result['status'] === 304) {
            return ['success' => true, 'cached' => true, 'body' => null, 'etag' => $storedEtag, 'status' => 304];
        }

        if ($result['status'] === 200 && $result['body'] !== null) {
            File::put($filePath, $result['body']);
            $this->writeEtag($etagPath, $result['etag']);

            return ['success' => true, 'cached' => false, 'body' => $result['body'], 'etag' => $result['etag'], 'status' => 200];
        }

        if ($result['status'] !== null && $result['status'] >= 400) {
            return ['success' => false, 'cached' => false, 'body' => null, 'etag' => null, 'status' => $result['status']];
        }

        return null;
    }

    /**
     * Perform a conditional GET with If-None-Match.
     *
     * @param  string  $url  URL to request
     * @param  string  $etag  Stored ETag value (with or without quotes)
     * @return array{status: int|null, body: string|null, etag: string|null}
     */
    private function conditionalGet(string $url, string $etag): array
    {
        $value = str_contains($etag, '"') ? $etag : '"'.$etag.'"';
        $response = Http::timeout(self::TIMEOUT_SECONDS)
            ->withHeaders(['If-None-Match' => $value])
            ->get($url);

        $status = $response->status();
        $body = $status === 200 ? $response->body() : null;
        $responseEtag = $response->header('ETag');

        return [
            'status' => $status,
            'body' => $body,
            'etag' => $responseEtag,
        ];
    }

    private function readStoredEtag(string $etagPath): ?string
    {
        if (! File::exists($etagPath)) {
            return null;
        }
        $raw = trim(File::get($etagPath));

        return $raw === '' ? null : $raw;
    }

    private function writeEtag(string $etagPath, ?string $etag): void
    {
        if ($etag === null) {
            return;
        }
        File::put($etagPath, $etag);
    }
}
