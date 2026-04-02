<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI;

use App\Base\AI\Contracts\Tool;
use App\Base\AI\Services\WebSearchService;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Modules\Core\AI\Console\Commands\BrowserStatusCommand;
use App\Modules\Core\AI\Console\Commands\BrowserSweepCommand;
use App\Modules\Core\AI\Console\Commands\MemoryCompactCommand;
use App\Modules\Core\AI\Console\Commands\MemoryIndexCommand;
use App\Modules\Core\AI\Console\Commands\OperationsStatusCommand;
use App\Modules\Core\AI\Console\Commands\OperationsSweepCommand;
use App\Modules\Core\AI\Console\Commands\SchedulesTickCommand;
use App\Modules\Core\AI\Services\AgentExecutionContext;
use App\Modules\Core\AI\Services\AgenticRuntime;
use App\Modules\Core\AI\Services\AgentRuntime;
use App\Modules\Core\AI\Services\AgentToolRegistry;
use App\Modules\Core\AI\Services\BackgroundCommandService;
use App\Modules\Core\AI\Services\Browser\BrowserArtifactStore;
use App\Modules\Core\AI\Services\Browser\BrowserRuntimeAdapter;
use App\Modules\Core\AI\Services\Browser\BrowserSessionManager;
use App\Modules\Core\AI\Services\Browser\BrowserSessionRepository;
use App\Modules\Core\AI\Services\ConfigResolver;
use App\Modules\Core\AI\Services\KodiPromptFactory;
use App\Modules\Core\AI\Services\LaraCapabilityMatcher;
use App\Modules\Core\AI\Services\LaraContextProvider;
use App\Modules\Core\AI\Services\LaraNavigationRouter;
use App\Modules\Core\AI\Services\LaraOrchestrationService;
use App\Modules\Core\AI\Services\LaraPromptFactory;
use App\Modules\Core\AI\Services\LaraTaskDispatcher;
use App\Modules\Core\AI\Services\Memory\MemoryChunker;
use App\Modules\Core\AI\Services\Memory\MemoryCompactor;
use App\Modules\Core\AI\Services\Memory\MemoryHealthService;
use App\Modules\Core\AI\Services\Memory\MemoryIndexer;
use App\Modules\Core\AI\Services\Memory\MemoryRetrievalEngine;
use App\Modules\Core\AI\Services\Memory\MemorySourceCatalog;
use App\Modules\Core\AI\Services\MessageManager;
use App\Modules\Core\AI\Services\Messaging\Adapters\EmailAdapter;
use App\Modules\Core\AI\Services\Messaging\Adapters\SlackAdapter;
use App\Modules\Core\AI\Services\Messaging\Adapters\TelegramAdapter;
use App\Modules\Core\AI\Services\Messaging\Adapters\WhatsAppAdapter;
use App\Modules\Core\AI\Services\Messaging\ChannelAdapterRegistry;
use App\Modules\Core\AI\Services\Messaging\InboundRoutingService;
use App\Modules\Core\AI\Services\Messaging\InboundSignalService;
use App\Modules\Core\AI\Services\Messaging\OutboundMessageService;
use App\Modules\Core\AI\Services\ModelDiscoveryService;
use App\Modules\Core\AI\Services\OperationsDispatchService;
use App\Modules\Core\AI\Services\ProviderAuthFlowService;
use App\Modules\Core\AI\Services\Scheduling\ScheduleDefinitionService;
use App\Modules\Core\AI\Services\Scheduling\SchedulePlanner;
use App\Modules\Core\AI\Services\SessionManager;
use App\Modules\Core\AI\Services\ToolMetadataRegistry;
use App\Modules\Core\AI\Services\ToolReadinessService;
use App\Modules\Core\AI\Services\Workspace\PromptPackageFactory;
use App\Modules\Core\AI\Services\Workspace\PromptRenderer;
use App\Modules\Core\AI\Services\Workspace\WorkspaceResolver;
use App\Modules\Core\AI\Services\Workspace\WorkspaceValidator;
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
use App\Modules\Core\AI\Tools\MemoryStatusTool;
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
        $this->app->singleton(WorkspaceResolver::class);
        $this->app->singleton(WorkspaceValidator::class);
        $this->app->singleton(PromptPackageFactory::class);
        $this->app->singleton(PromptRenderer::class);
        $this->app->singleton(MemorySourceCatalog::class);
        $this->app->singleton(MemoryChunker::class);
        $this->app->singleton(MemoryIndexer::class);
        $this->app->singleton(MemoryRetrievalEngine::class);
        $this->app->singleton(MemoryCompactor::class);
        $this->app->singleton(MemoryHealthService::class);
        $this->app->singleton(LaraPromptFactory::class);
        $this->app->singleton(KodiPromptFactory::class);
        $this->app->singleton(AgentExecutionContext::class);

        // Browser subsystem
        $this->app->singleton(BrowserSessionRepository::class);
        $this->app->singleton(BrowserRuntimeAdapter::class);
        $this->app->singleton(BrowserSessionManager::class);
        $this->app->singleton(BrowserArtifactStore::class);

        $this->app->singleton(ChannelAdapterRegistry::class, function () {
            $registry = new ChannelAdapterRegistry;

            $registry->register(new WhatsAppAdapter);
            $registry->register(new TelegramAdapter);
            $registry->register(new SlackAdapter);
            $registry->register(new EmailAdapter);

            return $registry;
        });

        $this->app->singleton(OutboundMessageService::class);
        $this->app->singleton(InboundSignalService::class);
        $this->app->singleton(InboundRoutingService::class);

        // Scheduling subsystem
        $this->app->singleton(ScheduleDefinitionService::class);
        $this->app->singleton(SchedulePlanner::class);

        // Background command subsystem
        $this->app->singleton(BackgroundCommandService::class);

        // Operations dispatch (query/lifecycle)
        $this->app->singleton(OperationsDispatchService::class);

        $this->registerToolRegistries();

        $this->app->singleton(AgenticRuntime::class);
        $this->app->singleton(ToolReadinessService::class);
    }

    /**
     * Bootstrap Core AI services.
     *
     * Registers artisan commands for memory and browser management.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                MemoryIndexCommand::class,
                MemoryCompactCommand::class,
                BrowserSweepCommand::class,
                BrowserStatusCommand::class,
                SchedulesTickCommand::class,
                OperationsSweepCommand::class,
                OperationsStatusCommand::class,
            ]);
        }
    }

    /**
     * Build all tool instances once and wire both registries.
     *
     * The execution registry (AgentToolRegistry) receives only tools
     * that pass runtime availability checks. The metadata registry
     * (ToolMetadataRegistry) receives ALL 24 tools so the workspace UI can
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
     * Instantiate all 24 Agent tools (memoized).
     *
     * Returns three groups:
     * - 'always': Tools that are always available (22 tools)
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
            $app->make(ArtisanTool::class),
            new BashTool,
            $app->make(BrowserTool::class),
            $app->make(DelegateTaskTool::class),
            $app->make(DelegationStatusTool::class),
            new DocumentAnalysisTool,
            new EditDataTool,
            new EditFileTool,
            $app->make(GuideTool::class),
            new ImageAnalysisTool,
            $this->buildMemoryGetTool($app),
            $this->buildMemoryStatusTool($app),
            $app->make(MessageTool::class),
            new NavigateTool,
            new NotificationTool,
            new QueryDataTool,
            $app->make(ScheduleTaskTool::class),
            new SystemInfoTool,
            $app->make(TicketUpdateTool::class),
            $app->make(WebFetchTool::class),
            $app->make(AgentListTool::class),
            new WriteJsTool,
        ];

        $memorySearchTool = MemorySearchTool::createIfAvailable();

        if ($memorySearchTool !== null) {
            $memorySearchTool->setRetrievalEngine(
                $app->make(MemoryRetrievalEngine::class),
            );
        }
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

    /**
     * Build MemoryGetTool with catalog injection.
     */
    private function buildMemoryGetTool(Application $app): MemoryGetTool
    {
        $tool = new MemoryGetTool;
        $tool->setCatalog($app->make(MemorySourceCatalog::class));

        return $tool;
    }

    /**
     * Build MemoryStatusTool with health service injection.
     */
    private function buildMemoryStatusTool(Application $app): MemoryStatusTool
    {
        $tool = new MemoryStatusTool;
        $tool->setHealthService($app->make(MemoryHealthService::class));

        return $tool;
    }
}
