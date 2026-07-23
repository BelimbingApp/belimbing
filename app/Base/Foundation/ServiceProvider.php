<?php

namespace App\Base\Foundation;

use App\Base\Foundation\Console\Commands\WindowsSafeOctaneStartCommand;
use App\Base\Foundation\Console\Commands\WindowsSafeOctaneStartFrankenPhpCommand;
use App\Base\Foundation\Contracts\DataOperationRecorder;
use App\Base\Foundation\Contracts\DomainLifecycleLedger;
use App\Base\Foundation\Contracts\DomainRuntimeReloader;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use App\Base\Foundation\Services\NullDataOperationRecorder;
use App\Base\Foundation\Services\NullDomainLifecycleLedger;
use App\Base\Foundation\Services\NullDomainRuntimeReloader;
use App\Base\Foundation\Services\NullSemanticActionRecorder;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use Laravel\Octane\Commands\StartCommand as OctaneStartCommand;
use Laravel\Octane\Commands\StartFrankenPhpCommand as OctaneStartFrankenPhpCommand;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/domains.php', 'domains');

        $this->app->bindIf(DomainLifecycleLedger::class, NullDomainLifecycleLedger::class);
        $this->app->bindIf(DomainRuntimeReloader::class, NullDomainRuntimeReloader::class);
        $this->app->bindIf(SemanticActionRecorder::class, NullSemanticActionRecorder::class);
        $this->app->bindIf(DataOperationRecorder::class, NullDataOperationRecorder::class);

        // Same extend-the-binding pattern as the Database module's migrate
        // command overrides: Octane registers these classes directly.
        $this->app->extend(OctaneStartCommand::class, fn () => new WindowsSafeOctaneStartCommand);
        $this->app->extend(OctaneStartFrankenPhpCommand::class, fn () => new WindowsSafeOctaneStartFrankenPhpCommand);
    }

    public function boot(): void
    {
        // Route every `->links()` call through BLB's owned `x-ui` pagination
        // chrome (x-icon glyphs, whole-sentence translations) instead of the
        // framework's vendor-published views.
        Paginator::defaultView('ui.pagination-links');
        Paginator::defaultSimpleView('ui.pagination-simple-links');
    }
}
