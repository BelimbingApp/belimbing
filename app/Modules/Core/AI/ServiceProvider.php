<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI;

use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\DigitalWorkerRuntime;
use App\Modules\Core\AI\Services\LaraCapabilityMatcher;
use App\Modules\Core\AI\Services\LaraContextProvider;
use App\Modules\Core\AI\Services\LaraOrchestrationService;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\LaraTaskDispatcher;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\ModelDiscoveryService;
use App\Modules\Core\AI\Services\ProviderAuthFlowService;
use App\Modules\Core\AI\Services\SessionManager;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register Core AI services.
     *
     * Config is provided by Base AI (config key 'ai'). Core registers
     * governance services that depend on company/employee context.
     */
    public function register(): void
    {
        $this->app->singleton(ConfigResolver::class);
        $this->app->singleton(ModelDiscoveryService::class);
        $this->app->singleton(SessionManager::class);
        $this->app->singleton(MessageManager::class);
        $this->app->singleton(DigitalWorkerRuntime::class);
        $this->app->singleton(ProviderAuthFlowService::class);
        $this->app->singleton(LaraContextProvider::class);
        $this->app->singleton(LaraCapabilityMatcher::class);
        $this->app->singleton(LaraTaskDispatcher::class);
        $this->app->singleton(LaraOrchestrationService::class);
        $this->app->singleton(LaraPromptFactory::class);
    }
}
