<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\DataSharePackageExpectation;
use App\Base\Database\DTO\DataShare\DataShareTransferOfferBundle;
use App\Base\Database\Exceptions\DataShareTransportException;
use App\Base\Database\Models\DataShareReceipt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class DataShareOfferFetcher
{
    public function __construct(
        private readonly DataShareInstanceIdentityResolver $instances,
        private readonly DataShareDirectionPolicy $directions,
        private readonly DataShareScopeCatalog $catalog,
        private readonly DataShareUploadStager $uploads,
        private readonly DataSharePrivateStorage $storage,
        private readonly DataSharePackageInbox $inbox,
        private readonly DataShareEventRecorder $events,
        private readonly DataShareSettings $settings,
    ) {}

    public function fetch(DataShareTransferOfferBundle $offer): DataShareReceipt
    {
        $this->assertLocalPolicy($offer);
        $temporary = tempnam(sys_get_temp_dir(), 'blb-data-share-fetch-');

        if ($temporary === false) {
            throw DataShareTransportException::protectedReceiptStorageUnavailable();
        }

        @chmod($temporary, 0600);

        try {
            try {
                $response = Http::accept('application/x-ndjson')
                    ->withToken($offer->secret)
                    ->connectTimeout(15)
                    ->timeout($this->settings->integer('data_share.offers.fetch_timeout_seconds', 600, 30, 7200))
                    ->withOptions([
                        'sink' => $temporary,
                        'on_headers' => function (ResponseInterface $response) use ($offer): void {
                            if ($response->getStatusCode() !== 200
                                || $response->getHeaderLine('Content-Length') !== (string) $offer->bytes) {
                                throw DataShareTransportException::fetchFailed(__('the response status or declared byte count is invalid.'));
                            }
                        },
                        'progress' => function (int $downloadTotal, int $downloaded) use ($offer): void {
                            if ($downloadTotal > $offer->bytes || $downloaded > $offer->bytes) {
                                throw DataShareTransportException::fetchFailed(__('the response exceeded its advertised package size.'), 413);
                            }
                        },
                    ])
                    ->get($offer->endpoint);
            } catch (ConnectionException $e) {
                throw DataShareTransportException::fetchFailed($e->getMessage());
            } catch (Throwable $e) {
                if ($e instanceof DataShareTransportException) {
                    throw $e;
                }

                throw DataShareTransportException::fetchFailed($e->getMessage());
            }

            if ($response->status() !== 200
                || $response->header('X-Data-Share-Offer-Id') !== $offer->offerId
                || $response->header('X-Data-Share-Package-Id') !== $offer->packageId
                || $response->header('X-Data-Share-Package-Sha256') !== $offer->packageSha256
                || ! is_file($temporary)
                || filesize($temporary) !== $offer->bytes) {
                throw DataShareTransportException::fetchFailed(__('the response metadata or completed byte count did not match the offer.'));
            }

            $stream = fopen($temporary, 'rb');
            try {
                $staged = $this->uploads->stage($stream, 'offer', $offer->bytes, $offer->bytes);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }

            try {
                $receipt = $this->inbox->receiveFromProtectedPath(
                    $staged->path,
                    DataSharePackageExpectation::fromOffer($offer),
                );
                $this->events->recordReceipt('offer_fetched', $receipt, [
                    'offer_id' => $offer->offerId,
                    'endpoint_host' => parse_url($offer->endpoint, PHP_URL_HOST),
                ]);

                return $receipt;
            } finally {
                $this->storage->disk()->delete($staged->path);
            }
        } finally {
            @unlink($temporary);
        }
    }

    private function assertLocalPolicy(DataShareTransferOfferBundle $offer): void
    {
        $maximum = $this->settings->integer('data_share.transfer_limits.max_package_bytes', 250 * 1024 * 1024, 1, 2147483647);

        if ($offer->isExpired()) {
            throw DataShareTransportException::fetchFailed(__('the transfer offer has expired.'), 410);
        }

        if ($offer->bytes > $maximum) {
            throw DataShareTransportException::fetchFailed(__('the package exceeds this target’s local byte limit.'), 413);
        }

        $this->catalog->scope($offer->scope);
        $this->directions->assertAllowed($offer->source, $this->instances->current());
    }
}
