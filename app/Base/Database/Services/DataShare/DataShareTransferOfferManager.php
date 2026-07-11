<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\DataShareTransferOfferBundle;
use App\Base\Database\Exceptions\DataSharePolicyException;
use App\Base\Database\Exceptions\DataShareTransportException;
use App\Base\Database\Models\DataShareTransferOffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class DataShareTransferOfferManager
{
    public function __construct(
        private readonly DataSharePackageExporter $exporter,
        private readonly DataShareInstanceIdentityResolver $instances,
        private readonly DataSharePrivateStorage $storage,
        private readonly DataShareEventRecorder $events,
        private readonly DataShareSettings $settings,
    ) {}

    /** @param list<string> $tables */
    public function publish(
        string $scopeName,
        array $tables,
        string $expectedPreviewHash,
        ?int $actorId = null,
    ): DataShareTransferOfferBundle {
        $source = $this->instances->current();
        $offerId = Str::lower((string) Str::ulid());
        $endpoints = $this->offerEndpoints($offerId);
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $expiresAt = CarbonImmutable::now('UTC')->addMinutes(
            $this->settings->integer('data_share.offers.expiry_minutes', 60, 1, 10080),
        );
        $export = $this->exporter->export(
            $scopeName,
            $tables,
            $offerId,
            $expiresAt->toIso8601String(),
            $expectedPreviewHash,
        );

        try {
            $offer = DB::transaction(function () use ($actorId, $expiresAt, $export, $offerId, $scopeName, $secret, $source): DataShareTransferOffer {
                $offer = DataShareTransferOffer::query()->create([
                    'offer_id' => $offerId,
                    'secret_hash' => hash('sha256', $secret),
                    'published_by_actor_id' => $actorId ?? auth()->id(),
                    'package_id' => $export->packageId,
                    'package_sha256' => $export->sha256,
                    'package_path' => $export->path,
                    'source_instance_id' => $source->id,
                    'source_role' => $source->role->value,
                    'scope_name' => $scopeName,
                    'bytes' => $export->bytes,
                    'metadata' => [
                        'counts' => $export->manifest['counts'],
                        'payloads' => $export->manifest['payloads'],
                        'tables' => $export->manifest['scope']['tables'],
                    ],
                    'status' => 'published',
                    'expires_at' => $expiresAt,
                ]);
                $this->events->recordOffer('offer_published', $offer);

                return $offer;
            });
        } catch (\Throwable $e) {
            $this->storage->disk()->delete($export->path);
            throw $e;
        }

        return new DataShareTransferOfferBundle(
            endpoint: $endpoints[0],
            endpoints: $endpoints,
            offerId: $offerId,
            secret: $secret,
            source: $source,
            scope: $scopeName,
            packageId: $export->packageId,
            packageSha256: $export->sha256,
            bytes: $export->bytes,
            counts: $export->manifest['counts'],
            expiresAt: $expiresAt->toIso8601String(),
        );
    }

    public function authenticate(string $offerId, string $secret): DataShareTransferOffer
    {
        $offer = DataShareTransferOffer::query()->where('offer_id', $offerId)->first();

        if ($offer?->status === 'published' && $offer->expires_at->isPast()) {
            $offer->forceFill(['status' => 'expired'])->save();
            $this->events->recordOffer('offer_expired', $offer);
        }

        if ($offer === null
            || $offer->status !== 'published'
            || $secret === ''
            || ! hash_equals($offer->secret_hash, hash('sha256', $secret))) {
            throw DataShareTransportException::invalidTransferOffer();
        }

        return $offer;
    }

    public function revoke(DataShareTransferOffer $offer): void
    {
        if ($offer->status !== 'published' || $offer->expires_at->isPast()) {
            throw DataSharePolicyException::offerNotRevocable($offer->expires_at->isPast() ? 'expired' : $offer->status);
        }

        $offer->forceFill([
            'status' => 'revoked',
            'revoked_at' => now('UTC'),
        ])->save();
        $this->events->recordOffer('offer_revoked', $offer);
    }

    public function recordDownload(DataShareTransferOffer $offer): void
    {
        DataShareTransferOffer::query()->whereKey($offer->id)->increment('download_count', 1, [
            'last_downloaded_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
        $offer->refresh();
        $this->events->recordOffer('offer_downloaded', $offer, ['download_count' => $offer->download_count]);
    }

    /** @return list<string> */
    private function offerEndpoints(string $offerId): array
    {
        $baseUrls = $this->settings->stringList('data_share.offers.base_urls');

        if ($baseUrls === []) {
            $endpoint = Route::has('data-share.offers.show')
                ? route('data-share.offers.show', ['offerId' => $offerId])
                : rtrim((string) config('app.url'), '/').'/data-share/offers/'.$offerId;

            return [$this->assertEndpoint($endpoint)];
        }

        if (count($baseUrls) > 5) {
            throw DataSharePolicyException::tooManyOfferBaseUrls(5);
        }

        return array_values(array_unique(array_map(
            fn (string $baseUrl): string => $this->assertEndpoint(
                rtrim(trim($baseUrl), '/').'/data-share/offers/'.$offerId,
            ),
            $baseUrls,
        )));
    }

    private function assertEndpoint(string $endpoint): string
    {
        $parts = parse_url($endpoint);

        if (filter_var($endpoint, FILTER_VALIDATE_URL) === false
            || ! is_array($parts)
            || ($parts['scheme'] ?? null) !== 'https'
            || ! is_string($parts['host'] ?? null)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            throw DataSharePolicyException::invalidOfferBaseUrl($endpoint);
        }

        return $endpoint;
    }
}
