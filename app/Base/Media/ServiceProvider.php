<?php
namespace App\Base\Media;

use App\Base\Media\Services\MediaAssetStore;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(MediaAssetStore::class);
    }
}
