<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI;

use App\Base\AI\Contracts\Tool;
use App\Base\AI\Services\WebSearchService;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Modules\Core\AI\Services\AgentExecutionContext;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\AgentRuntime;
use App\Modules\Core\AI\Services\AgentToolRegistry;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\KodiPromptFactory;
use App\Modules\Core\AI\Services\LaraCapabilityMatcher;
use App\Modules\Core\AI\Services\LaraContextProvider;
use App\Modules\Core\AI\Services\LaraNavigationRouter;
use App\Modules\Core\AI\Services\LaraOrchestrationService;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\LaraTaskDispatcher;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\Messaging\Adapters\EmailAdapter;
use App\Modules\Core\AI\Services\Messaging\Adapters\SlackAdapter;
use App\Modules\Core\AI\Services\Messaging\Adapters\TelegramAdapter;
use App\Modules\Core\AI\Services\Messaging\Adapters\WhatsAppAdapter;
use App\Modules\Core\AI\Services\Messaging\ChannelAdapterRegistry;
use App\Modules\Core\AI\Services\ModelDiscoveryService;
use App\Modules\Core\AI\Services\ProviderAuthFlowService;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\AI\Services\ToolMetadataRegistry;
use App\Modules\Core\AI\Services\ToolReadinessService;
use App\Modules\Core\AI\Tools\AgentListTool;
use App\Modules\Core\AI\Tools\ArtisanTool;
use App\Modules\Core\AI\Tools\BashTool;
use App\Modules\Core\AI\Tools\BrowserTool;
use App\Modules\Core\AI\Tools\DelegateTaskTool;
use App\Modules\Core\AI\Tools\DelegationStatusTool;
use App\Modules\Core\AI\Tools\DocumentAnalysisTool;
use App\Modules\Core\AI\Tools\EditDataTool;
use App\Modules\Core\AI\Tools\EditFileTool;
use App\Modules\Core\AI\Tools\GuideTool;
use App\Modules\Core\AI\Tools\ImageAnalysisTool;
use App\Modules\Core\AI\Tools\MemoryGetTool;
use App\Modules\Core\AI\Tools\MemorySearchTool;
use App\Modules\Core\AI\Tools\MessageTool;
use App\Modules\Core\AI\Tools\NavigateTool;
use App\Modules\Core\AI\Tools\NotificationTool;
use App\Modules\Core\AI\Tools\QueryDataTool;
use App\Modules\Core\AI\Tools\ScheduleTaskTool;
use App\Modules\Core\AI\Tools\SystemInfoTool;
use App\Modules\Core\AI\Tools\TicketUpdateTool;
use App\Modules\Core\AI\Tools\WebFetchTool;
use App\Modules\Core\AI\Tools\WebSearchTool;
use App\Modules\Core\AI\Tools\WriteJsTool;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Cached tool instances shared between execution and metadata registries.
     *
     * @var array{always: list<Tool>, conditional: list<?Tool>, metadataFallbacks: list<Tool>}|null
     */
    private ?array $toolInstances = null;

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
        $this->app->singleton(AgentRuntime::class);
        $this->app->singleton(ProviderAuthFlowService::class);
        $this->app->singleton(LaraContextProvider::class);
        $this->app->singleton(LaraCapabilityMatcher::class);
        $this->app->singleton(LaraTaskDispatcher::class);
        $this->app->singleton(LaraNavigationRouter::class);
        $this->app->singleton(LaraOrchestrationService::class);
        $this->app->singleton(LaraPromptFactory::class);
        $this->app->singleton(KodiPromptFactory::class);
        $this->app->singleton(AgentExecutionContext::class);

        $this->app->singleton(ChannelAdapterRegistry::class, function () {
            $registry = new ChannelAdapterRegistry;

            $registry->register(new WhatsAppAdapter);
            $registry->register(new TelegramAdapter);
            $registry->register(new SlackAdapter);
            $registry->register(new EmailAdapter);

            return $registry;
        });

        $this->registerToolRegistries();

        $this->app->singleton(AgenticRuntime::class);
        $this->app->singleton(ToolReadinessService::class);
    }

    /**
     * Build all tool instances once and wire both registries.
     *
     * The execution registry (AgentToolRegistry) receives only tools
     * that pass runtime availability checks. The metadata registry
     * (ToolMetadataRegistry) receives ALL 23 tools so the workspace UI can
     * display setup instructions even for unconfigured tools.
     */
    private function registerToolRegistries(): void
    {
        $this->app->singleton(AgentToolRegistry::class, function ($app) {
            $tools = $this->resolveToolInstances($app);
            $registry = new AgentToolRegistry(
                $app->make(AuthorizationService::class),
            );

            foreach ($tools['always'] as $tool) {
                $registry->register($tool);
            }

            foreach ($tools['conditional'] as $tool) {
                if ($tool !== null) {
                    $registry->register($tool);
                }
            }

            return $registry;
        });

        $this->app->singleton(ToolMetadataRegistry::class, function ($app) {
            $tools = $this->resolveToolInstances($app);

            $allTools = [...$tools['always'], ...$tools['metadataFallbacks']];

            foreach ($tools['conditional'] as $tool) {
                if ($tool !== null) {
                    $allTools[] = $tool;
                }
            }

            return new ToolMetadataRegistry($allTools);
        });
    }

    /**
     * Instantiate all 23 Agent tools (memoized).
     *
     * Returns three groups:
     * - 'always': Tools that are always available (21 tools)
     * - 'conditional': Tools that depend on runtime config (may be null)
     * - 'metadataFallbacks': Metadata-only instances for conditional tools
     *   that failed availability checks — safe to call metadata methods on
     *   but not suitable for execution
     *
     * @return array{always: list<Tool>, conditional: list<?Tool>, metadataFallbacks: list<Tool>}
     */
    private function resolveToolInstances(Application $app): array
    {
        if ($this->toolInstances !== null) {
            return $this->toolInstances;
        }

        $always = [
            new ArtisanTool,
            new BashTool,
            $app->make(BrowserTool::class),
            $app->make(DelegateTaskTool::class),
            new DelegationStatusTool,
            new DocumentAnalysisTool,
            new EditDataTool,
            new EditFileTool,
            $app->make(GuideTool::class),
            new ImageAnalysisTool,
            new MemoryGetTool,
            $app->make(MessageTool::class),
            new NavigateTool,
            new NotificationTool,
            new QueryDataTool,
            new ScheduleTaskTool,
            new SystemInfoTool,
            $app->make(TicketUpdateTool::class),
            $app->make(WebFetchTool::class),
            $app->make(AgentListTool::class),
            new WriteJsTool,
        ];

        $memorySearchTool = MemorySearchTool::createIfAvailable();
        $webSearchTool = WebSearchTool::createIfConfigured(
            $app->make(WebSearchService::class),
        );

        $conditional = [$memorySearchTool, $webSearchTool];

        // Metadata-only fallbacks for conditional tools that aren't available.
        // These instances are safe to read metadata from (displayName, summary,
        // etc. return static values) but will not be registered for execution.
        $metadataFallbacks = [];

        if ($memorySearchTool === null) {
            $metadataFallbacks[] = new MemorySearchTool;
        }

        if ($webSearchTool === null) {
            $metadataFallbacks[] = new WebSearchTool;
        }

        $this->toolInstances = [
            'always' => $always,
            'conditional' => $conditional,
            'metadataFallbacks' => $metadataFallbacks,
        ];

        return $this->toolInstances;
    }
}
