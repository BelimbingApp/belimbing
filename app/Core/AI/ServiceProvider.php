<?php

namespace App\Core\AI;

use App\Base\AI\Contracts\AiProviderFamily;
use App\Base\AI\Contracts\Tool;
use App\Base\AI\Contracts\Tracing\LlmTraceContextFactory;
use App\Base\AI\Services\WebSearchService;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Database\Contracts\DevelopmentSanitizationContributor;
use App\Base\Media\PhotoCleanup\Contracts\ImageProviderCredentialStore as ImageProviderCredentialStoreContract;
use App\Base\Menu\Services\MenuConditionRegistry;
use App\Base\Settings\Services\SettingDefinitionRegistry;
use App\Core\AI\Console\Commands\BrowserStatusCommand;
use App\Core\AI\Console\Commands\BrowserSweepCommand;
use App\Core\AI\Console\Commands\CodexAuthListenCommand;
use App\Core\AI\Console\Commands\HealthSnapshotCommand;
use App\Core\AI\Console\Commands\InspectRunCommand;
use App\Core\AI\Console\Commands\LifecycleExecuteCommand;
use App\Core\AI\Console\Commands\LifecyclePreviewCommand;
use App\Core\AI\Console\Commands\MemoryCompactCommand;
use App\Core\AI\Console\Commands\MemoryIndexCommand;
use App\Core\AI\Console\Commands\OperationsStatusCommand;
use App\Core\AI\Console\Commands\OperationsSweepCommand;
use App\Core\AI\Console\Commands\PricingSnapshotRefreshCommand;
use App\Core\AI\Console\Commands\ReapOrphanRunsCommand;
use App\Core\AI\Console\Commands\SchedulesTickCommand;
use App\Core\AI\Console\Commands\SweepStaleTurnsCommand;
use App\Core\AI\Console\Commands\ToolStatsCommand;
use App\Core\AI\Contracts\AgentTaskContextContributor;
use App\Core\AI\Providers\Families\LlmProviderFamily;
use App\Core\AI\Services\AgentExecutionContext;
use App\Core\AI\Services\AgentTaskPromptFactory;
use App\Core\AI\Services\AgentToolRegistry;
use App\Core\AI\Services\AiProviderFamilyRegistry;
use App\Core\AI\Services\BackgroundCommandService;
use App\Core\AI\Services\Browser\BrowserArtifactStore;
use App\Core\AI\Services\Browser\BrowserRuntimeAdapter;
use App\Core\AI\Services\Browser\BrowserSessionManager;
use App\Core\AI\Services\Browser\BrowserSessionRepository;
use App\Core\AI\Services\ConfigResolver;
use App\Core\AI\Services\ControlPlane\HealthAndPresenceService;
use App\Core\AI\Services\ControlPlane\LifecycleControlService;
use App\Core\AI\Services\ControlPlane\OperationalTelemetryService;
use App\Core\AI\Services\ControlPlane\PolicyEvaluationService;
use App\Core\AI\Services\ControlPlane\RunDiagnosticService;
use App\Core\AI\Services\ControlPlane\RunInspectionService;
use App\Core\AI\Services\ControlPlane\WireLogger;
use App\Core\AI\Services\ControlPlane\WireLoggingTraceContextFactory;
use App\Core\AI\Services\HeadlessCli\HeadlessCliExecutor;
use App\Core\AI\Services\HeadlessCli\HeadlessCliProcessExecutor;
use App\Core\AI\Services\ImageProviderCredentialStore;
use App\Core\AI\Services\LaraCapabilityMatcher;
use App\Core\AI\Services\LaraContextProvider;
use App\Core\AI\Services\LaraNavigationRouter;
use App\Core\AI\Services\LaraOrchestrationService;
use App\Core\AI\Services\LaraPromptFactory;
use App\Core\AI\Services\LaraTaskDispatcher;
use App\Core\AI\Services\LaraTaskExecutionProfileRegistry;
use App\Core\AI\Services\LaraTaskProfileSelector;
use App\Core\AI\Services\LaraTaskRegistry;
use App\Core\AI\Services\Memory\MemoryChunker;
use App\Core\AI\Services\Memory\MemoryCompactor;
use App\Core\AI\Services\Memory\MemoryHealthService;
use App\Core\AI\Services\Memory\MemoryIndexer;
use App\Core\AI\Services\Memory\MemoryRetrievalEngine;
use App\Core\AI\Services\Memory\MemorySourceCatalog;
use App\Core\AI\Services\MessageManager;
use App\Core\AI\Services\Messaging\Adapters\EmailAdapter;
use App\Core\AI\Services\Messaging\Adapters\SlackAdapter;
use App\Core\AI\Services\Messaging\Adapters\TelegramAdapter;
use App\Core\AI\Services\Messaging\Adapters\WhatsAppAdapter;
use App\Core\AI\Services\Messaging\ChannelAdapterRegistry;
use App\Core\AI\Services\Messaging\InboundRoutingService;
use App\Core\AI\Services\Messaging\InboundSignalService;
use App\Core\AI\Services\Messaging\OutboundMessageService;
use App\Core\AI\Services\ModelDiscoveryService;
use App\Core\AI\Services\OperationsDispatchService;
use App\Core\AI\Services\Orchestration\AgentCapabilityCatalog;
use App\Core\AI\Services\Orchestration\FilesystemSkillPackLoader;
use App\Core\AI\Services\Orchestration\OrchestrationPolicyService;
use App\Core\AI\Services\Orchestration\RuntimeHookRegistry;
use App\Core\AI\Services\Orchestration\RuntimeHookRunner;
use App\Core\AI\Services\Orchestration\SessionSpawnManager;
use App\Core\AI\Services\Orchestration\SkillContextInjectionHook;
use App\Core\AI\Services\Orchestration\SkillContextResolver;
use App\Core\AI\Services\Orchestration\SkillPackRegistry;
use App\Core\AI\Services\Orchestration\SkillPacks\KnowledgeSkillPack;
use App\Core\AI\Services\Orchestration\SkillSelectionService;
use App\Core\AI\Services\Orchestration\TaskRoutingService;
use App\Core\AI\Services\PageContextHolder;
use App\Core\AI\Services\PageContextResolver;
use App\Core\AI\Services\Pricing\RefreshPricingSnapshot;
use App\Core\AI\Services\ProviderAuthFlowService;
use App\Core\AI\Services\RepositorySurfaceResolver;
use App\Core\AI\Services\Runtime\AgenticRuntime;
use App\Core\AI\Services\Runtime\RuntimeSessionContext;
use App\Core\AI\Services\Scheduling\AiScheduleDevelopmentSanitizer;
use App\Core\AI\Services\Scheduling\ScheduleDefinitionContributor;
use App\Core\AI\Services\Scheduling\ScheduleDefinitionService;
use App\Core\AI\Services\Scheduling\SchedulePlanner;
use App\Core\AI\Services\SessionManager;
use App\Core\AI\Services\TaskModelRecommendationService;
use App\Core\AI\Services\ToolMetadataRegistry;
use App\Core\AI\Services\ToolReadinessService;
use App\Core\AI\Services\Workspace\PromptPackageFactory;
use App\Core\AI\Services\Workspace\PromptRenderer;
use App\Core\AI\Services\Workspace\WorkspaceResolver;
use App\Core\AI\Services\Workspace\WorkspaceValidator;
use App\Core\AI\Tools\ActivePageSnapshotTool;
use App\Core\AI\Tools\AgentListTool;
use App\Core\AI\Tools\ArtisanTool;
use App\Core\AI\Tools\BashTool;
use App\Core\AI\Tools\BrowserTool;
use App\Core\AI\Tools\DelegateTaskTool;
use App\Core\AI\Tools\DelegationStatusTool;
use App\Core\AI\Tools\DocumentAnalysisTool;
use App\Core\AI\Tools\EditTool;
use App\Core\AI\Tools\GuideTool;
use App\Core\AI\Tools\ImageAnalysisTool;
use App\Core\AI\Tools\LoadSkillTool;
use App\Core\AI\Tools\MemoryGetTool;
use App\Core\AI\Tools\MemorySearchTool;
use App\Core\AI\Tools\MemoryStatusTool;
use App\Core\AI\Tools\MessageTool;
use App\Core\AI\Tools\NavigateTool;
use App\Core\AI\Tools\NotificationTool;
use App\Core\AI\Tools\ReadOnlyBrowserTool;
use App\Core\AI\Tools\ReadTool;
use App\Core\AI\Tools\ScheduleTaskTool;
use App\Core\AI\Tools\SearchTool;
use App\Core\AI\Tools\SystemInfoTool;
use App\Core\AI\Tools\VisibleNavMenuSnapshotTool;
use App\Core\AI\Tools\WebFetchTool;
use App\Core\AI\Tools\WebSearchTool;
use App\Core\AI\Tools\WriteJsTool;
use App\Core\Employee\Models\Employee;
use Illuminate\Console\Scheduling\Schedule;
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
        $this->mergeConfigFrom(__DIR__.'/Config/headless.php', 'ai-headless');

        $this->app->singleton(ConfigResolver::class);
        $this->app->singleton(ModelDiscoveryService::class);

        // AI provider families: the family-neutral spine over LLM, image, and
        // future families. Families self-register through the container tag
        // (like AI tools); the registry collects them for the providers hub.
        // The LLM family is #1; Base/Media tags the image family (PhotoRoom).
        $this->app->tag([LlmProviderFamily::class], AiProviderFamily::CONTAINER_TAG);
        $this->app->singleton(AiProviderFamilyRegistry::class, fn (Application $app): AiProviderFamilyRegistry => new AiProviderFamilyRegistry(
            $app->tagged(AiProviderFamily::CONTAINER_TAG),
        ));
        $this->app->singleton(ImageProviderCredentialStoreContract::class, ImageProviderCredentialStore::class);
        $this->app->singleton(SessionManager::class);
        $this->app->singleton(MessageManager::class);
        $this->app->singleton(LaraTaskRegistry::class);
        $this->app->singleton(LaraTaskExecutionProfileRegistry::class);
        $this->app->singleton(LaraTaskProfileSelector::class);
        $this->app->singleton(TaskModelRecommendationService::class);
        $this->app->singleton(ProviderAuthFlowService::class);
        $this->app->singleton(RepositorySurfaceResolver::class);
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
        $this->app->singleton(AgentTaskPromptFactory::class, fn (Application $app): AgentTaskPromptFactory => new AgentTaskPromptFactory(
            $app->make(WorkspaceResolver::class),
            $app->make(WorkspaceValidator::class),
            $app->make(PromptPackageFactory::class),
            $app->tagged(AgentTaskContextContributor::CONTAINER_TAG),
        ));
        $this->app->singleton(AgentExecutionContext::class);
        $this->app->scoped(RuntimeSessionContext::class);

        // Page context (request-scoped for isolation between requests)
        $this->app->scoped(PageContextHolder::class);
        $this->app->singleton(PageContextResolver::class);

        // Orchestration subsystem
        $this->app->singleton(OrchestrationPolicyService::class);
        $this->app->singleton(AgentCapabilityCatalog::class);
        $this->app->singleton(TaskRoutingService::class);
        $this->app->singleton(SessionSpawnManager::class);
        $this->app->singleton(SkillPackRegistry::class);
        $this->app->singleton(FilesystemSkillPackLoader::class);
        $this->app->singleton(SkillContextResolver::class);
        $this->app->singleton(SkillSelectionService::class);
        $this->app->singleton(RuntimeHookRegistry::class);
        $this->app->singleton(RuntimeHookRunner::class);

        $this->registerSkillPacks();
        $this->registerSkillRuntimeHooks();

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

        // Schedule subsystem
        $this->app->singleton(HeadlessCliProcessExecutor::class);
        $this->app->singleton(HeadlessCliExecutor::class);
        $this->app->singleton(ScheduleDefinitionService::class);
        $this->app->singleton(SchedulePlanner::class);
        $this->app->tag(ScheduleDefinitionContributor::class, 'schedule.contributors');
        $this->app->tag(AiScheduleDevelopmentSanitizer::class, DevelopmentSanitizationContributor::CONTAINER_TAG);

        // Background command subsystem
        $this->app->singleton(BackgroundCommandService::class);

        // Operations dispatch (query/lifecycle)
        $this->app->singleton(OperationsDispatchService::class);

        // Control plane subsystem
        $this->app->singleton(RunInspectionService::class);
        $this->app->scoped(RunDiagnosticService::class);
        $this->app->singleton(OperationalTelemetryService::class);
        $this->app->singleton(HealthAndPresenceService::class);
        $this->app->singleton(LifecycleControlService::class);
        $this->app->singleton(PolicyEvaluationService::class);
        $this->app->singleton(WireLogger::class);
        $this->app->singleton(LlmTraceContextFactory::class, WireLoggingTraceContextFactory::class);
        $this->app->singleton(RefreshPricingSnapshot::class);

        $this->registerToolRegistries();

        $this->app->singleton(AgenticRuntime::class);
        $this->app->singleton(ToolReadinessService::class);

        $this->app->afterResolving(MenuConditionRegistry::class, function (MenuConditionRegistry $registry): void {
            $registry->register('ai.lara_activated', static fn (mixed $user): bool => Employee::laraActivationState() === true);
        });
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
                ReapOrphanRunsCommand::class,
                SweepStaleTurnsCommand::class,
                ToolStatsCommand::class,
                InspectRunCommand::class,
                HealthSnapshotCommand::class,
                LifecyclePreviewCommand::class,
                LifecycleExecuteCommand::class,
                PricingSnapshotRefreshCommand::class,
                CodexAuthListenCommand::class,
            ]);
        }

        // Register on booted() (not bootstrap/app.php withSchedule): Laravel's
        // withSchedule only attaches when Artisan starts, so the admin Schedule
        // page would otherwise omit these while schedule:list still showed them.
        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);

            $schedule->command('blb:ai:schedules:tick')
                ->everyMinute()
                ->withoutOverlapping();
            $schedule->command('blb:ai:runs:reap-orphans')->everyFiveMinutes();
            $schedule->command('blb:ai:turns:sweep-stale')->everyFiveMinutes();
            $schedule->command('blb:ai:pricing:refresh')->dailyAt('02:30')->withoutOverlapping();
        });
    }

    /**
     * Register built-in skill packs with the SkillPackRegistry.
     *
     * Packs are registered during service provider boot (deferred
     * resolution). Each pack builds its manifest from live data.
     */
    private function registerSkillPacks(): void
    {
        $this->app->afterResolving(SkillPackRegistry::class, function (SkillPackRegistry $registry): void {
            $pack = $this->app->make(KnowledgeSkillPack::class);
            $registry->register($pack->manifest());

            foreach ($this->app->make(FilesystemSkillPackLoader::class)->load() as $manifest) {
                if (! $registry->has($manifest->id)) {
                    $registry->register($manifest);
                }
            }
        });
    }

    private function registerSkillRuntimeHooks(): void
    {
        $this->app->afterResolving(RuntimeHookRegistry::class, function (RuntimeHookRegistry $registry): void {
            if (! $registry->hasIdentifier('skill.context-injection')) {
                $registry->register($this->app->make(SkillContextInjectionHook::class));
            }
        });
    }

    /**
     * Build all tool instances once and wire both registries.
     *
     * The execution registry (AgentToolRegistry) receives only tools
     * that pass runtime availability checks. The metadata registry
     * (ToolMetadataRegistry) receives all built-in tools so the workspace UI can
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

            return new ToolMetadataRegistry(
                $allTools,
                $app->make(SettingDefinitionRegistry::class),
            );
        });
    }

    /**
     * Instantiate all always-on Agent tools (memoized).
     *
     * Returns three groups:
     * - 'always': Tools that are always available (core set; see resolveToolInstances)
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
            $app->make(ActivePageSnapshotTool::class),
            $app->make(ArtisanTool::class),
            $app->make(BashTool::class),
            $app->make(BrowserTool::class),
            $app->make(ReadOnlyBrowserTool::class),
            $app->make(DelegateTaskTool::class),
            $app->make(DelegationStatusTool::class),
            $app->make(DocumentAnalysisTool::class),
            $app->make(EditTool::class),
            $app->make(GuideTool::class),
            new ImageAnalysisTool,
            $app->make(LoadSkillTool::class),
            $this->buildMemoryGetTool($app),
            $this->buildMemoryStatusTool($app),
            $app->make(MessageTool::class),
            new NavigateTool,
            $app->make(VisibleNavMenuSnapshotTool::class),
            new NotificationTool,
            $app->make(ReadTool::class),
            $app->make(ScheduleTaskTool::class),
            $app->make(SearchTool::class),
            new SystemInfoTool,
            $app->make(WebFetchTool::class),
            $app->make(AgentListTool::class),
            new WriteJsTool,
        ];

        // Tools owned by other modules register through the container tag so
        // Core/AI never imports domain classes.
        foreach ($app->tagged(Tool::CONTAINER_TAG) as $taggedTool) {
            $always[] = $taggedTool;
        }

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
