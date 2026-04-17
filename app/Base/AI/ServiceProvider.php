<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI;

use App\Base\AI\Console\Commands\AiCatalogSyncCommand;
use App\Base\AI\Providers\Help\ProviderHelpRegistry;
use App\Base\AI\Services\GithubCopilotAuthService;
use App\Base\AI\Services\KnowledgeNavigator;
use App\Base\AI\Services\LlmClient;
use App\Base\AI\Services\ModelCatalogQueryService;
use App\Base\AI\Services\ModelCatalogService;
use App\Base\AI\Services\ProviderDiscoveryService;
use App\Base\AI\Services\ProviderMapping\ProviderCapabilityRegistry;
use App\Base\AI\Services\ProviderMapping\ProviderRequestMapperRegistry;
use App\Base\AI\Services\UrlSafetyGuard;
use App\Base\AI\Services\VectorStoreService;
use App\Base\AI\Services\WebFetchService;
use App\Base\AI\Services\WebSearchService;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register Base AI services.
     *
     * Merges the AI config (workspace_path, llm defaults, provider overlay)
     * and registers stateless infrastructure services as singletons.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/ai.php', 'ai');

        $this->app->singleton(ModelCatalogService::class);
        $this->app->singleton(ModelCatalogQueryService::class);
        $this->app->singleton(ProviderCapabilityRegistry::class);
        $this->app->singleton(ProviderRequestMapperRegistry::class);
        $this->app->singleton(LlmClient::class);
        $this->app->singleton(KnowledgeNavigator::class);
        $this->app->singleton(ProviderDiscoveryService::class);
        $this->app->singleton(UrlSafetyGuard::class);
        $this->app->singleton(WebFetchService::class);
        $this->app->singleton(WebSearchService::class);
        $this->app->singleton(GithubCopilotAuthService::class);
        $this->app->singleton(VectorStoreService::class);
        $this->app->singleton(ProviderHelpRegistry::class);
    }

    /**
     * Bootstrap Base AI services.
     *
     * Registers artisan commands for catalog management.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                AiCatalogSyncCommand::class,
            ]);
        }
    }
}
