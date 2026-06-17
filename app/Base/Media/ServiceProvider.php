<?php

namespace App\Base\Media;

use App\Base\AI\Contracts\AiProviderFamily;
use App\Base\Media\PhotoCleanup\Contracts\PhotoCleanupProvider;
use App\Base\Media\PhotoCleanup\ImageProviderFamily;
use App\Base\Media\PhotoCleanup\PhotoRoomClient;
use App\Base\Media\Services\MediaAssetStore;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaAssetStore::class);

        // PhotoRoom is the default (and currently only) cleanup provider.
        // Additional providers swap in here without touching the engine.
        $this->app->bind(PhotoCleanupProvider::class, PhotoRoomClient::class);

        // Contribute the image-processing family to the AI providers hub. The
        // tag keeps Core/AI from importing Media — it discovers families like
        // it discovers tools. See docs/plans/ai-provider-families.md.
        $this->app->tag([ImageProviderFamily::class], AiProviderFamily::CONTAINER_TAG);
    }
}
