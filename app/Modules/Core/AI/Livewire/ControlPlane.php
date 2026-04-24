<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
//
// Operator Control Plane - unified view for run inspection, health, and lifecycle controls.

namespace App\Modules\Core\AI\Livewire;

use App\Modules\Core\AI\DTO\ControlPlane\HealthSnapshot;
use App\Modules\Core\AI\DTO\ControlPlane\LifecyclePreview;
use App\Modules\Core\AI\DTO\ControlPlane\LifecycleRequest as LifecycleRequestDTO;
use App\Modules\Core\AI\DTO\ControlPlane\RunInspection;
use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Enums\LifecycleAction;
use App\Modules\Core\AI\Livewire\Concerns\ManagesWireLogWindow;
use App\Modules\Core\AI\Models\AiProvider;
use App\Modules\Core\AI\Models\ChatTurn;
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
    use ManagesWireLogWindow;
    use WithPagination;

    public string $activeTab = 'inspector';

    public string $inspectRunId = '';

    public bool $recentRunsCollapsed = false;

    public string $recentRunsSearch = '';

    public string $inspectTurnId = '';

    public int $healthAgentId = Employee::LARA_ID;

    /** @var list<array{id: int, label: string}> */
    public array $agentOptions = [];

    /** @var list<array<string, mixed>> */
    public array $recentTurns = [];

    public string $inspectionError = '';

    public string $turnInspectionError = '';

    /** @var list<array<string, mixed>> */
    public array $toolSnapshots = [];

    /** @var list<array<string, mixed>> */
    public array $providerSnapshots = [];

    /** @var array<string, mixed>|null */
    public ?array $agentSnapshot = null;

    /** @var array<string, int>|null */
    public ?array $turnHealthCounts = null;

    public string $lifecycleAction = '';

    public int $lifecycleEmployeeId = Employee::LARA_ID;

    public int $lifecycleRetentionDays = 7;

    public int $lifecycleStaleMinutes = 30;

    public string $lifecycleSessionId = '';

    /** @var array<string, mixed>|null */
    public ?array $lifecyclePreview = null;

    /** @var array<string, mixed>|null */
    public ?array $lifecycleResult = null;

    public string $lifecycleError = '';

    /** @var list<array<string, mixed>> */
    public array $recentLifecycleRequests = [];

    public function mount(): void
    {
        $this->activeTab = $this->resolveTab((string) request()->query('tab', 'inspector'));
        $this->inspectRunId = (string) (request()->query('runId') ?? request()->query('inspectRunId') ?? '');
        $this->inspectTurnId = (string) (request()->query('turnId') ?? '');
        $this->lifecycleRetentionDays = app(WireLogger::class)->retentionDays();

        $this->loadAgentOptions();
        $this->refreshInspectorLists();
        $this->refreshHealthSnapshots();
        $this->loadRecentLifecycleRequests();

        if ($this->inspectRunId !== '') {
            $this->resetWireLogWindow();
            $this->inspectRun();
        }

        if ($this->inspectTurnId !== '') {
            $this->inspectTurn();
        }
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

    public function inspectTurn(): void
    {
        $this->activeTab = 'turns';
        $this->turnInspectionError = '';

        if ($this->inspectTurnId === '') {
            $this->turnInspectionError = __('Turn ID is required.');

            return;
        }

        if (app(RunDiagnosticService::class)->buildTurnView($this->inspectTurnId) === null) {
            $this->turnInspectionError = __('Turn not found.');
        }
    }

    public function inspectRecentRun(string $runId): void
    {
        $this->inspectRunId = $runId;
        $this->resetWireLogWindow();
        $this->inspectRun();
    }

    public function inspectRecentTurn(string $turnId): void
    {
        $this->inspectTurnId = $turnId;
        $this->inspectTurn();
    }

    public function refreshInspectorLists(): void
    {
        $service = app(RunDiagnosticService::class);
        $this->recentTurns = $service->recentTurns();
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
        $this->loadTurnHealthCounts();
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

    public function loadTurnHealthCounts(): void
    {
        $now = now();

        $this->turnHealthCounts = [
            'queued' => ChatTurn::query()->where('status', 'queued')->count(),
            'booting' => ChatTurn::query()->where('status', 'booting')->count(),
            'running' => ChatTurn::query()->where('status', 'running')->count(),
            'stale_queued' => ChatTurn::query()
                ->whereIn('status', ['queued', 'booting'])
                ->where('created_at', '<', $now->copy()->subMinutes(10))
                ->count(),
            'stale_running' => ChatTurn::query()
                ->where('status', 'running')
                ->where('created_at', '<', $now->copy()->subMinutes(30))
                ->count(),
            'failed_last_hour' => ChatTurn::query()
                ->where('status', 'failed')
                ->where('finished_at', '>=', $now->copy()->subHour())
                ->count(),
            'completed_last_hour' => ChatTurn::query()
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

    public function render(): View
    {
        $diagnostics = app(RunDiagnosticService::class);
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
        $turnView = $this->inspectTurnId !== ''
            ? $diagnostics->buildTurnView($this->inspectTurnId)
            : null;

        return view('livewire.admin.ai.control-plane', [
            'activeTab' => $this->activeTab,
            'recentRuns' => $recentRuns,
            'runView' => $this->mapRunView($runView),
            'turnView' => $turnView,
            'wireLogDiskUsageBytes' => $diagnostics->wireLogDiskUsageBytes(),
            'selectedLifecycleAction' => $this->resolveLifecycleAction(),
            'operationsBreadcrumb' => $this->operationsBreadcrumb(),
        ]);
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

    private function resolveTab(string $tab): string
    {
        return in_array($tab, ['inspector', 'turns', 'health', 'lifecycle'], true)
            ? $tab
            : 'inspector';
    }

    /**
     * @return array{label: string, url: string|null}|null
     */
    private function operationsBreadcrumb(): ?array
    {
        if ((string) request()->query('from') !== 'operations') {
            return null;
        }

        $returnTo = (string) request()->query('returnTo', '');

        return [
            'label' => __('AI / Operations'),
            'url' => str_starts_with($returnTo, '/') ? $returnTo : null,
        ];
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
        };
    }

    /**
     * @param  array{
     *     inspection: RunInspection,
     *     transcript: list<Message>,
     *     triggering_prompt: Message|null,
     *     wire_log_entries: list<array{
     *         at: string|null,
     *         type: string|null,
     *         payload_pretty: string,
     *         payload_truncated: bool
     *     }>,
     *     wire_log_summary: array{
     *         footprint_bytes: int,
     *         total_entries: int,
     *         visible_entries: int,
     *         offset: int,
     *         limit: int,
     *         range_start: int,
     *         range_end: int,
     *         omitted_before: int,
     *         omitted_after: int,
     *         has_previous: bool,
     *         has_next: bool,
     *         last_offset: int
     *     },
     *     wire_logging_enabled: bool,
     *     turn_id: string|null
     * }|null  $runView
     * @return array<string, mixed>|null
     */
    private function mapRunView(?array $runView): ?array
    {
        if ($runView === null) {
            return null;
        }

        return [
            'inspection' => $this->mapRunInspection($runView['inspection']),
            'transcript' => $runView['transcript'],
            'triggering_prompt' => $runView['triggering_prompt'],
            'wire_log_entries' => $runView['wire_log_entries'],
            'wire_log_summary' => $runView['wire_log_summary'],
            'wire_logging_enabled' => $runView['wire_logging_enabled'],
            'turn_id' => $runView['turn_id'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mapRunInspection(RunInspection $run): array
    {
        $data = $run->toArray();
        $status = $run->status;

        $data['status_label'] = $status?->label();
        $data['status_color'] = $status?->color();
        $data['outcome_label'] = ucfirst((string) $data['outcome']);
        $data['outcome_color'] = match ($data['outcome']) {
            'success' => 'success',
            'error' => 'danger',
            'cancelled' => 'warning',
            default => 'default',
        };

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapHealthSnapshot(HealthSnapshot $snapshot): array
    {
        $data = $snapshot->toArray();

        $data['readiness_label'] = $snapshot->readiness->label();
        $data['readiness_color'] = $snapshot->readiness->color();
        $data['health_label'] = $snapshot->health->label();
        $data['health_color'] = $snapshot->health->color();
        $data['presence_label'] = $snapshot->presence->label();
        $data['presence_color'] = $snapshot->presence->color();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLifecyclePreview(LifecyclePreview $preview): array
    {
        $data = $preview->toArray();
        $data['action_label'] = $preview->action->label();

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function mapLifecycleRequest(LifecycleRequestDTO $request): array
    {
        $data = $request->toArray();
        $data['action_label'] = $request->action->label();
        $data['status_label'] = $request->status->label();
        $data['status_color'] = $request->status->color();
        $data['preview'] = $request->preview ? $this->mapLifecyclePreview($request->preview) : null;

        return $data;
    }
}
