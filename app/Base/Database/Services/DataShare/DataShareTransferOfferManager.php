<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\DataShareInstanceIdentity;
use App\Base\Database\DTO\DataShare\DataShareTransferOfferBundle;
use App\Base\Database\Enums\DataShareInstanceRole;
use App\Base\Database\Exceptions\DataSharePolicyException;
use App\Base\Database\Exceptions\DataShareTransportException;
use App\Base\Database\Models\DataShareTransferOffer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class DataShareTransferOfferManager
{
    public const MAX_DOWNLOADS = 100;

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
        ?int $maxDownloads = null,
    ): DataShareTransferOfferBundle {
        if ($maxDownloads !== null && ($maxDownloads < 1 || $maxDownloads > self::MAX_DOWNLOADS)) {
            throw DataSharePolicyException::invalidMaximumDownloads(self::MAX_DOWNLOADS);
        }

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
            $offer = DB::transaction(function () use ($actorId, $expiresAt, $export, $maxDownloads, $offerId, $scopeName, $secret, $source): DataShareTransferOffer {
                $offer = DataShareTransferOffer::query()->create([
                    'offer_id' => $offerId,
                    'secret_hash' => hash('sha256', $secret),
                    'secret' => $secret,
                    'max_downloads' => $maxDownloads,
                    'published_by_actor_id' => $actorId ?? auth()->id(),
                    'package_id' => $export->packageId,
                    'package_sha256' => $export->sha256,
                    'package_path' => $export->path,
                    'source_instance_id' => $source->id,
                    'source_name' => $source->name,
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

    public function bundleFor(DataShareTransferOffer $offer): DataShareTransferOfferBundle
    {
        $this->refreshAvailability($offer);

        if ($offer->status !== 'published') {
            throw DataSharePolicyException::offerNotCopyable($offer->status);
        }

        $endpoints = $this->offerEndpoints($offer->offer_id);

        return new DataShareTransferOfferBundle(
            endpoint: $endpoints[0],
            endpoints: $endpoints,
            offerId: $offer->offer_id,
            secret: $offer->secret,
            source: new DataShareInstanceIdentity(
                $offer->source_instance_id,
                $offer->source_name,
                DataShareInstanceRole::from($offer->source_role),
            ),
            scope: $offer->scope_name,
            packageId: $offer->package_id,
            packageSha256: $offer->package_sha256,
            bytes: $offer->bytes,
            counts: $offer->metadata['counts'],
            expiresAt: $offer->expires_at->toIso8601String(),
        );
    }

    public function refreshAvailability(DataShareTransferOffer $offer): DataShareTransferOffer
    {
        $offer->refresh();
        $this->refreshAvailabilityState($offer);

        return $offer;
    }

    public function authenticate(string $offerId, string $secret): DataShareTransferOffer
    {
        $offer = DataShareTransferOffer::query()->where('offer_id', $offerId)->first();
        $this->refreshAvailabilityState($offer);

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

    /**
     * Atomically consume one fetch slot before streaming. A row lock serializes
     * concurrent fetches so the configured maximum stays an enforceable boundary,
     * and the claim is persisted with an Eloquent save so the fetch is captured
     * as an audit mutation (visible in the offer's record history).
     */
    public function claimDownload(DataShareTransferOffer $offer): DataShareTransferOffer
    {
        $claimed = DB::transaction(function () use ($offer): ?DataShareTransferOffer {
            $locked = DataShareTransferOffer::query()->whereKey($offer->id)->lockForUpdate()->first();

            if ($locked === null || $locked->status !== 'published') {
                return null;
            }

            // Persist lazy expiry/exhaustion transitions inside the lock; returning
            // null (rather than throwing) keeps these committed while signalling refusal.
            if ($locked->expires_at->isPast()) {
                $locked->forceFill(['status' => 'expired'])->save();
                $this->events->recordOffer('offer_expired', $locked);

                return null;
            }

            if ($this->fetchLimitReached($locked)) {
                $locked->forceFill(['status' => 'exhausted'])->save();
                $this->events->recordOffer('offer_exhausted', $locked);

                return null;
            }

            $locked->download_count += 1;
            $locked->last_downloaded_at = now('UTC');
            $locked->save();
            $this->events->recordOffer('offer_downloaded', $locked, ['download_count' => $locked->download_count]);

            if ($this->fetchLimitReached($locked)) {
                $locked->forceFill(['status' => 'exhausted'])->save();
                $this->events->recordOffer('offer_exhausted', $locked);
            }

            return $locked;
        });

        if ($claimed === null) {
            throw DataShareTransportException::invalidTransferOffer();
        }

        return $claimed;
    }

    public function fetchLimitReached(DataShareTransferOffer $offer): bool
    {
        return $offer->max_downloads !== null && $offer->download_count >= $offer->max_downloads;
    }

    private function refreshAvailabilityState(?DataShareTransferOffer $offer): void
    {
        if ($offer?->status === 'published' && $offer->expires_at->isPast()) {
            $offer->forceFill(['status' => 'expired'])->save();
            $this->events->recordOffer('offer_expired', $offer);
        }

        if ($offer?->status === 'published' && $this->fetchLimitReached($offer)) {
            $offer->forceFill(['status' => 'exhausted'])->save();
            $this->events->recordOffer('offer_exhausted', $offer);
        }
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
