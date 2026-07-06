<?php

namespace App\Base\Media\Http\Controllers;

use App\Base\Media\Models\MediaAsset;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaAssetController
{
    /**
     * Only these raster types render inline. Everything else — SVG, HTML,
     * PDFs, unknown types — is forced to download so user-uploaded markup can
     * never execute script in the app origin (stored XSS). The stored MIME is
     * trusted only when it is on this list; otherwise the response is served
     * as an opaque octet-stream attachment.
     *
     * @var list<string>
     */
    private const INLINE_SAFE_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
    ];

    public function stream(MediaAsset $asset): StreamedResponse|Response
    {
        $disk = Storage::disk($asset->disk);

        if (! $disk->exists($asset->storage_key)) {
            abort(404);
        }

        $storedMime = strtolower(trim((string) ($asset->mime_type ?? '')));
        $isInlineSafe = in_array($storedMime, self::INLINE_SAFE_MIME_TYPES, true);

        $contentType = $isInlineSafe ? $storedMime : 'application/octet-stream';
        $disposition = $isInlineSafe ? 'inline' : 'attachment';
        $filename = $asset->original_filename ?? basename($asset->storage_key);

        // Laravel builds a correctly escaped Content-Disposition (RFC 6266) from
        // the name + disposition, so a crafted filename cannot inject headers.
        return $disk->response(
            $asset->storage_key,
            $filename,
            [
                'Content-Type' => $contentType,
                // Stop content-type sniffing and neutralize any markup a browser
                // still tries to render, even for the inline-safe raster types.
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; sandbox",
            ],
            $disposition,
        );
    }
}
