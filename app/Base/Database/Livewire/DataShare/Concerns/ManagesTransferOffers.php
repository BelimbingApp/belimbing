<?php

namespace App\Base\Database\Livewire\DataShare\Concerns;

use App\Base\Database\DTO\DataShare\DataShareTransferOfferBundle;
use App\Base\Database\Exceptions\DataShareTransportException;
use App\Base\Database\Models\DataShareTransferOffer;
use App\Base\Database\Services\DataShare\DataShareDirectionPolicy;
use App\Base\Database\Services\DataShare\DataShareInstanceIdentityResolver;
use App\Base\Database\Services\DataShare\DataShareOfferFetcher;
use App\Base\Database\Services\DataShare\DataShareScopeCatalog;
use App\Base\Database\Services\DataShare\DataShareTransferOfferManager;
use Throwable;

trait ManagesTransferOffers
{
    public function reviewOffer(): void
    {
        $this->requireCapability('admin.system.data-share-offer.accept');

        try {
            $offer = DataShareTransferOfferBundle::fromJson($this->offerBundle);
            app(DataShareDirectionPolicy::class)
                ->assertAllowed($offer->source, app(DataShareInstanceIdentityResolver::class)->current());
            app(DataShareScopeCatalog::class)->scope($offer->scope);

            if ($offer->isExpired()) {
                throw DataShareTransportException::expiredTransferOffer();
            }

            $this->offerEndpoint = $offer->endpoint;
            $this->offerEndpoints = $offer->endpoints;
            $this->reviewedOffer = [
                'offer_id' => $offer->offerId,
                'source_id' => $offer->source->id,
                'source_name' => $offer->source->name,
                'source_role' => $offer->source->role->value,
                'scope' => $offer->scope,
                'package_id' => $offer->packageId,
                'sha256' => $offer->packageSha256,
                'bytes' => $offer->bytes,
                'counts' => $offer->counts,
                'expires_at' => $offer->expiresAt,
            ];
            $this->setStatus(__('Offer from :source is ready for review. Fetching it will not plan or apply data.', [
                'source' => $offer->source->name,
            ]), 'success');
        } catch (Throwable $e) {
            $this->clearReviewedOffer();
            $this->setStatus($e->getMessage(), 'danger');
        }
    }

    public function fetchOffer(DataShareOfferFetcher $fetcher): void
    {
        $this->requireCapability('admin.system.data-share-offer.accept');

        try {
            $offer = DataShareTransferOfferBundle::fromJson($this->offerBundle);

            if ($this->offerEndpoint !== '') {
                $offer = $offer->usingEndpoint($this->offerEndpoint);
            }

            $receipt = $fetcher->fetch($offer);
            $this->setStatus(__('Package :package was fetched and verified into Incoming with SHA-256 :hash.', [
                'package' => $receipt->package_id,
                'hash' => $receipt->package_sha256,
            ]), 'success');
            $this->offerBundle = '';
            $this->clearReviewedOffer();
            $this->refreshOperations();
        } catch (Throwable $e) {
            $this->setStatus($e->getMessage(), 'danger');
        }
    }

    public function revokeOffer(int $offerId, DataShareTransferOfferManager $manager): void
    {
        $this->requireCapability('admin.system.data-share-offer.manage');
        $manager->revoke(DataShareTransferOffer::query()->findOrFail($offerId));
        $this->setStatus(__('Transfer offer revoked.'), 'success');
        $this->refreshOperations();
    }

    public function copyOfferBundle(int $offerId, DataShareTransferOfferManager $manager): void
    {
        $this->requireCapability('admin.system.data-share-offer.manage');

        try {
            $offer = DataShareTransferOffer::query()->findOrFail($offerId);
            $bundle = $manager->bundleFor($offer);

            $this->dispatch('data-share-bundle-ready', bundle: $bundle->toJson(), offerId: $offer->offer_id);
        } catch (Throwable $e) {
            $this->setStatus($e->getMessage(), 'danger');
            $this->refreshOperations();
        }
    }

    public function offerBundleCopied(string $offerId): void
    {
        $this->requireCapability('admin.system.data-share-offer.manage');
        $this->setStatus(__('Transfer offer :offer bundle copied. Paste it on the target instance.', [
            'offer' => $offerId,
        ]), 'success');
    }

    public function offerBundleCopyFailed(): void
    {
        $this->requireCapability('admin.system.data-share-offer.manage');
        $this->setStatus(__('The transfer offer bundle could not be copied. Check browser clipboard permission and try again.'), 'danger');
    }

    public function clearPublishedOfferBundle(): void
    {
        $this->publishedOfferBundle = null;
    }

    public function updatedOfferBundle(): void
    {
        $this->clearReviewedOffer();
    }

    public function updatedOfferEndpoint(): void
    {
        // Route selection does not change immutable package identity.
    }

    private function clearReviewedOffer(): void
    {
        $this->offerEndpoint = '';
        $this->offerEndpoints = [];
        $this->reviewedOffer = null;
    }
}
