<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI;

use App\Base\AI\Console\Commands\AiCatalogSyncCommand;
use App\Base\AI\Providers\Help\ProviderHelpRegistry;
use App\Base\AI\Services\GithubCopilotAuthService;
use App\Base\AI\Services\LlmClient;
use App\Base\AI\Services\ModelCatalogService;
use App\Base\AI\Services\ProviderDiscoveryService;
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
        $this->app->singleton(LlmClient::class);
        $this->app->singleton(ProviderDiscoveryService::class);
        $this->app->singleton(GithubCopilotAuthService::class);
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
