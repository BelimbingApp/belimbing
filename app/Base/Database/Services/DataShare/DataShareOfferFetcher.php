<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\DataSharePackageExpectation;
use App\Base\Database\DTO\DataShare\DataShareTransferOfferBundle;
use App\Base\Database\DTO\DataShare\StagedDataShareUpload;
use App\Base\Database\Exceptions\DataShareTransportException;
use App\Base\Database\Models\DataShareReceipt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response as HttpResponse;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class DataShareOfferFetcher
{
    public function __construct(
        private readonly DataShareScopeCatalog $catalog,
        private readonly DataShareUploadStager $uploads,
        private readonly DataSharePrivateStorage $storage,
        private readonly DataSharePackageInbox $inbox,
        private readonly DataShareEventRecorder $events,
        private readonly DataShareSettings $settings,
    ) {}

    public function fetch(DataShareTransferOfferBundle $offer): DataShareReceipt
    {
        try {
            return $this->fetchOffer($offer);
        } catch (Throwable $e) {
            $this->events->recordFailure('fetch_failed', [
                'offer_id' => $offer->offerId,
                'package_id' => $offer->packageId,
                'bytes' => $offer->bytes,
                'endpoint_host' => parse_url($offer->endpoint, PHP_URL_HOST),
                'scope_name' => $offer->scope,
            ], $e);

            throw $e;
        }
    }

    private function fetchOffer(DataShareTransferOfferBundle $offer): DataShareReceipt
    {
        $this->assertLocalPolicy($offer);
        $temporary = $this->temporaryDownloadPath();

        try {
            $response = $this->downloadOffer($offer, $temporary);
            $this->assertCompletedResponse($response, $offer, $temporary);
            $staged = $this->stageDownloadedOffer($temporary, $offer->bytes);

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

    /**
     * @return array<string, mixed>
     */
    private function requestOptions(DataShareTransferOfferBundle $offer, string $temporary): array
    {
        $options = [
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
        ];

        return $this->withConnectionHintOptions($options, $offer);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function withConnectionHintOptions(array $options, DataShareTransferOfferBundle $offer): array
    {
        $hint = $offer->connectionHint();

        if ($hint === null) {
            return $options;
        }

        if (! defined('CURLOPT_PINNEDPUBLICKEY') || ! defined('CURLOPT_RESOLVE')) {
            throw DataShareTransportException::fetchFailed(__('this PHP cURL build cannot use the offer’s secure LAN connection hint.'));
        }

        $host = (string) parse_url($offer->endpoint, PHP_URL_HOST);
        $port = (int) (parse_url($offer->endpoint, PHP_URL_PORT) ?: 443);

        // The operator-delivered offer is the trust handoff. cURL connects to
        // its LAN address while authenticating the exact advertised TLS key.
        $options['verify'] = false;
        $options['curl'] = [
            CURLOPT_PINNEDPUBLICKEY => $hint['tls_public_key'],
            CURLOPT_RESOLVE => ["{$host}:{$port}:{$hint['address']}"],
        ];

        return $options;
    }

    private function temporaryDownloadPath(): string
    {
        $temporary = tempnam(sys_get_temp_dir(), 'blb-data-share-fetch-');

        if ($temporary === false) {
            throw DataShareTransportException::protectedReceiptStorageUnavailable();
        }

        @chmod($temporary, 0600);

        return $temporary;
    }

    private function downloadOffer(DataShareTransferOfferBundle $offer, string $temporary): HttpResponse
    {
        try {
            return Http::accept('application/x-ndjson')
                ->withToken($offer->secret)
                ->connectTimeout(15)
                ->timeout($this->settings->integer('data_share.offers.fetch_timeout_seconds', 600, 30, 7200))
                ->withOptions($this->requestOptions($offer, $temporary))
                ->get($offer->endpoint);
        } catch (ConnectionException $e) {
            throw DataShareTransportException::fetchFailed($e->getMessage());
        } catch (Throwable $e) {
            if ($e instanceof DataShareTransportException) {
                throw $e;
            }

            throw DataShareTransportException::fetchFailed($e->getMessage());
        }
    }

    private function assertCompletedResponse(HttpResponse $response, DataShareTransferOfferBundle $offer, string $temporary): void
    {
        if ($response->status() !== 200
            || $response->header('X-Data-Share-Offer-Id') !== $offer->offerId
            || $response->header('X-Data-Share-Package-Id') !== $offer->packageId
            || $response->header('X-Data-Share-Package-Sha256') !== $offer->packageSha256
            || ! is_file($temporary)
            || filesize($temporary) !== $offer->bytes) {
            throw DataShareTransportException::fetchFailed(__('the response metadata or completed byte count did not match the offer.'));
        }
    }

    private function stageDownloadedOffer(string $temporary, int $bytes): StagedDataShareUpload
    {
        $stream = fopen($temporary, 'rb');

        try {
            return $this->uploads->stage($stream, 'offer', $bytes, $bytes);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
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
    }
}
