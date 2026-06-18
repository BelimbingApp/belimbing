<?php

namespace App\Base\Foundation;

use App\Base\Foundation\Contracts\DomainLifecycleLedger;
use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Foundation\Services\NullDomainLifecycleLedger;
use App\Base\Foundation\Services\NullDomainRuntimeReloader;
use App\Base\Foundation\Services\NullSemanticActionRecorder;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/domains.php', 'domains');

        $this->app->bindIf(DomainLifecycleLedger::class, NullDomainLifecycleLedger::class);
        $this->app->bindIf(DomainRuntimeReloader::class, NullDomainRuntimeReloader::class);
        $this->app->bindIf(SemanticActionRecorder::class, NullSemanticActionRecorder::class);
    }
}
