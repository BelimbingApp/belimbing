<?php

namespace App\Base\Media;

use App\Base\AI\Contracts\AiProviderFamily;
use App\Base\Media\PhotoCleanup\Contracts\PhotoCleanupProvider;
use App\Base\Media\PhotoCleanup\ImageProviderFamily;
use App\Base\Media\PhotoCleanup\PhotoCleanupProviderRegistry;
use App\Base\Media\PhotoCleanup\ResolvingPhotoCleanupProvider;
use App\Base\Media\Services\MediaAssetStore;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaAssetStore::class);

        // The active cleanup adapter is chosen per company through
        // PhotoCleanupSelection (reads `media.photo_cleanup.provider`). The
        // resolving proxy delegates on each call so PhotoCleanupService stays
        // sealed — it depends only on the PhotoCleanupProvider contract.
        // Adding a provider is "ship a client + register it in
        // PhotoCleanupProviderRegistry", not "edit Base". See
        // docs/plans/media-photo-cleanup-providers.md.
        $this->app->singleton(PhotoCleanupProviderRegistry::class);
        $this->app->bind(PhotoCleanupProvider::class, ResolvingPhotoCleanupProvider::class);

        // Contribute the image-processing family to the AI providers hub. The
        // tag keeps Core/AI from importing Media — it discovers families like
        // it discovers tools. See docs/plans/ai-provider-families.md.
        $this->app->tag([ImageProviderFamily::class], AiProviderFamily::CONTAINER_TAG);
    }
}
