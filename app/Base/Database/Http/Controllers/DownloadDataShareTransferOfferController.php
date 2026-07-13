<?php

namespace App\Base\Database\Http\Controllers;

use App\Base\Database\Exceptions\DataShareTransportException;
use App\Base\Database\Services\DataShare\DataSharePrivateStorage;
use App\Base\Database\Services\DataShare\DataShareTransferOfferManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DownloadDataShareTransferOfferController
{
    public function __invoke(
        Request $request,
        string $offerId,
        DataShareTransferOfferManager $offers,
        DataSharePrivateStorage $storage,
    ): StreamedResponse|JsonResponse {
        try {
            $offer = $offers->authenticate($offerId, (string) $request->bearerToken());
        } catch (DataShareTransportException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        $disk = $storage->disk();

        if (! $disk->exists($offer->package_path) || $disk->size($offer->package_path) !== $offer->bytes) {
            return response()->json(['message' => __('The published Data Share package is unavailable.')], 410);
        }

        try {
            $offer = $offers->claimDownload($offer);
        } catch (DataShareTransportException $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->stream(function () use ($disk, $offer): void {
            $stream = $disk->readStream($offer->package_path);
            $output = fopen('php://output', 'wb');

            if ($stream === false || $output === false) {
                if (is_resource($stream)) {
                    fclose($stream);
                }

                return;
            }

            try {
                stream_copy_to_stream($stream, $output);
            } finally {
                fclose($stream);
                fclose($output);
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson',
            'Content-Length' => (string) $offer->bytes,
            'Cache-Control' => 'private, no-store',
            'X-Data-Share-Offer-Id' => $offer->offer_id,
            'X-Data-Share-Package-Id' => $offer->package_id,
            'X-Data-Share-Package-Sha256' => $offer->package_sha256,
        ]);
    }
}
