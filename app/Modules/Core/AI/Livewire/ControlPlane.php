<?php

//
// Operator Control Plane - unified view for run inspection, health, and lifecycle controls.

namespace App\Modules\Core\AI\Livewire;

use App\Base\AI\Services\AiRuntimeSettings;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Livewire\Concerns\InteractsWithNotifications;
use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\AI\DTO\ControlPlane\HealthSnapshot;
use App\Modules\Core\AI\DTO\ControlPlane\LifecycleRequest as LifecycleRequestDTO;
use App\Modules\Core\AI\Enums\LifecycleAction;
use App\Modules\Core\AI\Livewire\Concerns\ManagesWireLogWindow;
use App\Modules\Core\AI\Livewire\Concerns\MapsControlPlaneState;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Services\ControlPlane\HealthAndPresenceService;
use App\Modules\Core\AI\Services\ControlPlane\LifecycleControlService;
use App\Modules\Core\AI\Services\ControlPlane\RunDiagnosticService;
use App\Modules\Core\AI\Services\ControlPlane\WireLogger;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Contracts\View\View;
use Livewire\Component;
use Livewire\WithPagination;

class ControlPlane extends Component
{
    use InteractsWithNotifications;
    use ManagesWireLogWindow;
    use MapsControlPlaneState;
    use WithPagination;

    public string $activeTab = 'inspector';

    public string $inspectRunId = '';

    public bool $recentRunsCollapsed = false;

    public string $recentRunsSearch = '';

    public int $healthAgentId = Employee::LARA_ID;

    /** @var list<array{id: int, label: string}> */
    public array $agentOptions = [];

    public string $inspectionError = '';

    /** @var list<array<string, mixed>> */
    public array $toolSnapshots = [];

    /** @var list<array<string, mixed>> */
    public array $providerSnapshots = [];

    /** @var array<string, mixed>|null */
    public ?array $agentSnapshot = null;

    /** @var array<string, int>|null */
    public ?array $runHealthCounts = null;

    public string $lifecycleAction = '';

    public int $lifecycleEmployeeId = Employee::LARA_ID;

    public int $lifecycleRetentionDays = 7;

    public int $lifecycleStaleMinutes = 30;

    public string $lifecycleSessionId = '';

    public string $maxToolRounds = '';

    public string $laraPromptExtensionPath = '';

    public bool $bashToolEnabled = false;

    /** @var array<string, mixed>|null */
    public ?array $lifecyclePreview = null;

    /** @var array<string, mixed>|null */
    public ?array $lifecycleResult = null;

    public string $lifecycleError = '';

    /** @var list<array<string, mixed>> */
    public array $recentLifecycleRequests = [];

    public function mount(AiRuntimeSettings $runtimeSettings): void
    {
        $this->activeTab = $this->resolveTab((string) request()->query('tab', 'inspector'));
        $this->inspectRunId = (string) (request()->query('runId') ?? request()->query('inspectRunId') ?? '');

        $this->lifecycleRetentionDays = app(WireLogger::class)->retentionDays();
        $this->maxToolRounds = (string) $runtimeSettings->maxToolRounds();
        $this->laraPromptExtensionPath = $runtimeSettings->laraPromptExtensionPath() ?? '';
        $this->bashToolEnabled = $runtimeSettings->bashToolEnabled();

        $this->loadAgentOptions();
        $this->refreshInspectorLists();
        $this->refreshHealthSnapshots();
        $this->loadRecentLifecycleRequests();

        if ($this->inspectRunId !== '') {
            $this->resetWireLogWindow();
            $this->inspectRun();
        }
    }

    public function setActiveTab(string $tab): void
    {
        $this->activeTab = $this->resolveTab($tab);
    }

    public function inspectRun(): void
    {
        $this->activeTab = 'inspector';
        $this->inspectionError = '';

        if ($this->inspectRunId === '') {
            $this->inspectionError = __('Run ID is required.');

            return;
        }

        $runView = app(RunDiagnosticService::class)->buildRunView(
            $this->inspectRunId,
            wireLogOffset: $this->wireLogOffset,
            wireLogLimit: $this->wireLogLimit,
        );

        if ($runView === null) {
            $this->inspectionError = __('Run not found.');

            return;
        }

        $this->recentRunsCollapsed = true;
    }

    public function inspectRecentRun(string $runId): void
    {
        $this->inspectRunId = $runId;
        $this->resetWireLogWindow();
        $this->inspectRun();
    }

    public function refreshInspectorLists(): void
    {
        $this->resetPage();
    }

    public function updatedRecentRunsSearch(): void
    {
        $this->resetPage();
    }

    public function refreshHealthSnapshots(): void
    {
        $this->loadToolSnapshots();
        $this->loadProviderSnapshots();
        $this->loadAgentSnapshot();
        $this->loadRunHealthCounts();
    }

    public function loadToolSnapshots(): void
    {
        $service = app(HealthAndPresenceService::class);
        $this->toolSnapshots = array_map(
            fn (HealthSnapshot $snapshot): array => $this->mapHealthSnapshot($snapshot),
            $service->allToolSnapshots(),
        );
    }

    public function loadProviderSnapshots(): void
    {
        $service = app(HealthAndPresenceService::class);
        $providerNames = AiProvider::query()
            ->llm()
            ->active()
            ->orderBy('display_name')
            ->pluck('name')
            ->unique()
            ->values()
            ->all();

        $this->providerSnapshots = array_map(
            fn (string $providerName): array => $this->mapHealthSnapshot($service->providerSnapshot($providerName)),
            $providerNames,
        );
    }

    public function loadAgentSnapshot(): void
    {
        if ($this->healthAgentId <= 0) {
            $this->agentSnapshot = null;

            return;
        }

        $this->agentSnapshot = $this->mapHealthSnapshot(
            app(HealthAndPresenceService::class)->agentSnapshot($this->healthAgentId),
        );
    }

    public function loadRunHealthCounts(): void
    {
        $now = now();

        $this->runHealthCounts = [
            'queued' => AiRun::query()->where('status', 'queued')->count(),
            'booting' => AiRun::query()->where('status', 'booting')->count(),
            'running' => AiRun::query()->where('status', 'running')->count(),
            'stale_queued' => AiRun::query()
                ->whereIn('status', ['queued', 'booting'])
                ->where('created_at', '<', $now->copy()->subMinutes(10))
                ->count(),
            'stale_running' => AiRun::query()
                ->where('status', 'running')
                ->where('created_at', '<', $now->copy()->subMinutes(30))
                ->count(),
            'failed_last_hour' => AiRun::query()
                ->where('status', 'failed')
                ->where('finished_at', '>=', $now->copy()->subHour())
                ->count(),
            'completed_last_hour' => AiRun::query()
                ->where('status', 'completed')
                ->where('finished_at', '>=', $now->copy()->subHour())
                ->count(),
        ];
    }

    public function previewLifecycleAction(): void
    {
        $this->lifecyclePreview = null;
        $this->lifecycleResult = null;
        $this->lifecycleError = '';

        $action = $this->resolveLifecycleAction();

        if ($action === null) {
            $this->lifecycleError = __('Select a lifecycle action.');

            return;
        }

        $this->lifecyclePreview = $this->mapLifecyclePreview(
            app(LifecycleControlService::class)->preview($action, $this->buildLifecycleScope($action)),
        );
    }

    public function executeLifecycleAction(): void
    {
        $this->lifecycleResult = null;
        $this->lifecycleError = '';

        $action = $this->resolveLifecycleAction();

        if ($action === null) {
            $this->lifecycleError = __('Select a lifecycle action.');

            return;
        }

        $this->lifecycleResult = $this->mapLifecycleRequest(
            app(LifecycleControlService::class)->execute(
                $action,
                $this->buildLifecycleScope($action),
                requestedBy: auth()->id(),
            ),
        );

        $this->loadRecentLifecycleRequests();
    }

    public function loadRecentLifecycleRequests(): void
    {
        $this->recentLifecycleRequests = array_map(
            fn (LifecycleRequestDTO $request): array => $this->mapLifecycleRequest($request),
            app(LifecycleControlService::class)->recent(10),
        );
    }

    public function saveRuntimeGuardrails(
        SettingsService $settings,
        AiRuntimeSettings $runtimeSettings,
    ): void {
        $this->authorizeRuntimeGuardrailManagement();

        $validated = $this->validate([
            'maxToolRounds' => $runtimeSettings->maxToolRoundsRules(),
            'laraPromptExtensionPath' => $runtimeSettings
                ->definition(AiRuntimeSettings::LARA_PROMPT_EXTENSION_PATH_KEY)
                ->rules,
            'bashToolEnabled' => $runtimeSettings
                ->definition(AiRuntimeSettings::BASH_TOOL_ENABLED_KEY)
                ->rules,
        ]);

        $value = (int) $validated['maxToolRounds'];

        $settings->set(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY, $value);
        $extensionPath = trim((string) $validated['laraPromptExtensionPath']);

        if ($extensionPath === '') {
            $settings->forget(AiRuntimeSettings::LARA_PROMPT_EXTENSION_PATH_KEY);
        } else {
            $settings->set(AiRuntimeSettings::LARA_PROMPT_EXTENSION_PATH_KEY, $extensionPath);
        }

        $settings->set(
            AiRuntimeSettings::BASH_TOOL_ENABLED_KEY,
            (bool) $validated['bashToolEnabled'],
        );
        $this->maxToolRounds = (string) $value;
        $this->laraPromptExtensionPath = $extensionPath;
        $this->resetValidation();
        $this->notify(__('Runtime guardrails saved.'));
    }

    public function restoreRuntimeGuardrailDefaults(
        SettingsService $settings,
        AiRuntimeSettings $runtimeSettings,
    ): void {
        $this->authorizeRuntimeGuardrailManagement();

        $settings->forget(AiRuntimeSettings::MAX_TOOL_ROUNDS_KEY);
        $settings->forget(AiRuntimeSettings::LARA_PROMPT_EXTENSION_PATH_KEY);
        $settings->forget(AiRuntimeSettings::BASH_TOOL_ENABLED_KEY);
        $this->maxToolRounds = (string) $runtimeSettings->maxToolRounds();
        $this->laraPromptExtensionPath = $runtimeSettings->laraPromptExtensionPath() ?? '';
        $this->bashToolEnabled = $runtimeSettings->bashToolEnabled();
        $this->resetValidation();
        $this->notify(__('Runtime guardrail restored to its shipped default.'));
    }

    public function render(): View
    {
        $diagnostics = app(RunDiagnosticService::class);
        $maxToolRoundsDefinition = app(AiRuntimeSettings::class)->maxToolRoundsDefinition();
        $recentRuns = $diagnostics
            ->recentRunsQuery($this->recentRunsSearch)
            ->paginate(25)
            ->through(fn ($run): array => $diagnostics->mapRecentRun($run));

        $runView = $this->inspectRunId !== ''
            ? $diagnostics->buildRunView(
                $this->inspectRunId,
                wireLogOffset: $this->wireLogOffset,
                wireLogLimit: $this->wireLogLimit,
            )
            : null;

        return view('livewire.admin.ai.control-plane', [
            'activeTab' => $this->activeTab,
            'canManageRuntimeGuardrails' => $this->canManageRuntimeGuardrails(),
            'recentRuns' => $recentRuns,
            'runView' => $this->mapRunView($runView),
            'maxToolRoundsDefinition' => $maxToolRoundsDefinition,
            'laraPromptExtensionPathDefinition' => app(AiRuntimeSettings::class)
                ->definition(AiRuntimeSettings::LARA_PROMPT_EXTENSION_PATH_KEY),
            'bashToolEnabledDefinition' => app(AiRuntimeSettings::class)
                ->definition(AiRuntimeSettings::BASH_TOOL_ENABLED_KEY),
            'wireLogDiskUsageBytes' => $diagnostics->wireLogDiskUsageBytes(),
            'selectedLifecycleAction' => $this->resolveLifecycleAction(),
            'operationsBreadcrumb' => $this->operationsBreadcrumb(),
        ]);
    }

    private function canManageRuntimeGuardrails(): bool
    {
        $user = auth()->user();

        return $user !== null && app(AuthorizationService::class)
            ->can(Actor::forUser($user), 'admin.ai.control-plane.manage')
            ->allowed;
    }

    private function authorizeRuntimeGuardrailManagement(): void
    {
        $user = auth()->user();

        abort_if($user === null, 403);

        app(AuthorizationService::class)->authorize(
            Actor::forUser($user),
            'admin.ai.control-plane.manage',
        );
    }

    private function loadAgentOptions(): void
    {
        $this->agentOptions = Employee::query()
            ->agent()
            ->orderBy('short_name')
            ->orderBy('full_name')
            ->get(['id', 'short_name', 'full_name'])
            ->map(fn (Employee $employee): array => [
                'id' => $employee->id,
                'label' => $employee->displayName(),
            ])
            ->values()
            ->all();
    }

    private function resolveLifecycleAction(): ?LifecycleAction
    {
        return $this->lifecycleAction !== ''
            ? LifecycleAction::tryFrom($this->lifecycleAction)
            : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildLifecycleScope(LifecycleAction $action): array
    {
        return match ($action) {
            LifecycleAction::CompactMemory => [
                'employee_id' => $this->lifecycleEmployeeId,
            ],
            LifecycleAction::PruneSessions => [
                'employee_id' => $this->lifecycleEmployeeId,
                'retention_days' => $this->lifecycleRetentionDays,
            ],
            LifecycleAction::PruneArtifacts => array_filter([
                'session_id' => $this->lifecycleSessionId !== '' ? $this->lifecycleSessionId : null,
            ]),
            LifecycleAction::SweepBrowserSessions => [],
            LifecycleAction::SweepOperations => [
                'stale_minutes' => $this->lifecycleStaleMinutes,
            ],
            LifecycleAction::PruneWireLogs => [
                'retention_days' => $this->lifecycleRetentionDays,
            ],
            LifecycleAction::RefreshPricingSnapshot => [],
        };
    }
}
