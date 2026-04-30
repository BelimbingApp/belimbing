<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Media\Http\Controllers;

use App\Base\Media\Models\MediaAsset;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MediaAssetController
{
    public function stream(MediaAsset $asset): StreamedResponse|Response
    {
        $disk = Storage::disk($asset->disk);

        if (! $disk->exists($asset->storage_key)) {
            abort(404);
        }

        $mimeType = $asset->mime_type ?? 'application/octet-stream';
        $disposition = str_starts_with($mimeType, 'image/') ? 'inline' : 'attachment';
        $filename = $asset->original_filename ?? basename($asset->storage_key);

        return $disk->response($asset->storage_key, $filename, [
            'Content-Type' => $mimeType,
            'Content-Disposition' => $disposition.'; filename="'.addslashes($filename).'"',
        ]);
    }
}
