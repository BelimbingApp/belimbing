<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>
//
// Operator Control Plane — unified view for run inspection, health, and lifecycle controls.

namespace App\Modules\Core\AI\Livewire;

use App\Modules\Core\AI\DTO\ControlPlane\HealthSnapshot;
use App\Modules\Core\AI\DTO\ControlPlane\LifecyclePreview;
use App\Modules\Core\AI\DTO\ControlPlane\LifecycleRequest as LifecycleRequestDTO;
use App\Modules\Core\AI\DTO\ControlPlane\RunInspection;
use App\Modules\Core\AI\DTO\Message;
use App\Modules\Core\AI\Enums\LifecycleAction;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\ChatTurn;
use App\Modules\Core\AI\Services\ControlPlane\HealthAndPresenceService;
use App\Modules\Core\AI\Services\ControlPlane\LifecycleControlService;
use App\Modules\Core\AI\Services\ControlPlane\RunInspectionService;
use App\Modules\Core\AI\Services\MessageManager;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ControlPlane extends Component
{
    // -- Run Inspector state --

    public string $inspectRunId = '';

    public string $inspectSessionId = '';

    public int $inspectEmployeeId = 0;

    /** @var list<array<string, mixed>> */
    public array $runInspections = [];

    /** @var array<string, mixed>|null */
    public ?array $singleRunInspection = null;

    public string $inspectionError = '';

    // -- Health & Presence state --

    /** @var list<array<string, mixed>> */
    public array $toolSnapshots = [];

    /** @var array<string, mixed>|null */
    public ?array $agentSnapshot = null;

    public int $healthAgentId = 0;

    /** @var array<string, int>|null */
    public ?array $turnHealthCounts = null;

    // -- Turn Inspector state --

    public string $inspectTurnId = '';

    /** @var array<string, mixed>|null */
    public ?array $turnInspection = null;

    /** @var list<array<string, mixed>> */
    public array $turnEvents = [];

    public string $turnInspectionError = '';

    // -- Lifecycle state --

    public string $lifecycleAction = '';

    public int $lifecycleEmployeeId = 0;

    public int $lifecycleRetentionDays = 30;

    public int $lifecycleStaleMinutes = 30;

    public string $lifecycleSessionId = '';

    /** @var array<string, mixed>|null */
    public ?array $lifecyclePreview = null;

    /** @var array<string, mixed>|null */
    public ?array $lifecycleResult = null;

    public string $lifecycleError = '';

    /** @var list<array<string, mixed>> */
    public array $recentLifecycleRequests = [];

    // ---------------------------------------------------------------
    // Mount
    // ---------------------------------------------------------------

    public function mount(): void
    {
        $runId = request()->query('inspectRunId');
        if (is_string($runId) && $runId !== '') {
            $this->inspectRunId = $runId;
            $this->inspectRun();
        }
    }

    // ---------------------------------------------------------------
    // Run Inspector actions
    // ---------------------------------------------------------------

    /**
     * Inspect a single run by run ID.
     */
    public function inspectRun(): void
    {
        $this->resetRunInspection();

        if ($this->inspectRunId === '') {
            $this->inspectionError = __('Run ID is required.');

            return;
        }

        $service = app(RunInspectionService::class);
        $result = $service->inspectRun($this->inspectRunId);

        if ($result === null) {
            $this->inspectionError = __('Run not found.');

            return;
        }

        $this->singleRunInspection = $this->mapRunInspection($result);
    }

    /**
     * Inspect all runs in a session.
     */
    public function inspectSession(): void
    {
        $this->resetRunInspection();

        if ($this->inspectSessionId === '' || $this->inspectEmployeeId === 0) {
            $this->inspectionError = __('Session ID and Employee ID are required.');

            return;
        }

        $service = app(RunInspectionService::class);
        $results = $service->inspectSession(
            $this->inspectEmployeeId,
            $this->inspectSessionId,
        );

        if ($results === []) {
            $this->inspectionError = __('No runs found for the given session.');

            return;
        }

        $this->runInspections = array_map(fn (RunInspection $run): array => $this->mapRunInspection($run), $results);
    }

    // ---------------------------------------------------------------
    // Health & Presence actions
    // ---------------------------------------------------------------

    /**
     * Load health snapshots for all tools.
     */
    public function loadToolSnapshots(): void
    {
        $service = app(HealthAndPresenceService::class);
        $this->toolSnapshots = array_map(fn (HealthSnapshot $snapshot): array => $this->mapHealthSnapshot($snapshot), $service->allToolSnapshots());
    }

    /**
     * Load health snapshot for a specific agent.
     */
    public function loadAgentSnapshot(): void
    {
        $this->agentSnapshot = null;

        if ($this->healthAgentId === 0) {
            return;
        }

        $service = app(HealthAndPresenceService::class);
        $this->agentSnapshot = $this->mapHealthSnapshot($service->agentSnapshot($this->healthAgentId));
    }

    /**
     * Load turn health counts — active, stale, and recent failed turns.
     */
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

    // ---------------------------------------------------------------
    // Turn Inspector actions
    // ---------------------------------------------------------------

    /**
     * Inspect a turn by its ID.
     */
    public function inspectTurn(): void
    {
        $this->turnInspection = null;
        $this->turnEvents = [];
        $this->turnInspectionError = '';

        if ($this->inspectTurnId === '') {
            $this->turnInspectionError = __('Turn ID is required.');

            return;
        }

        $turn = ChatTurn::query()->find($this->inspectTurnId);

        if ($turn === null) {
            $this->turnInspectionError = __('Turn not found.');

            return;
        }

        $this->turnInspection = [
            'id' => $turn->id,
            'employee_id' => $turn->employee_id,
            'session_id' => $turn->session_id,
            'acting_for_user_id' => $turn->acting_for_user_id,
            'status' => $turn->status->value,
            'status_label' => $turn->status->label(),
            'status_color' => $turn->status->color(),
            'current_phase' => $turn->current_phase?->value,
            'current_phase_label' => $turn->current_phase?->label(),
            'current_label' => $turn->current_label,
            'last_event_seq' => $turn->last_event_seq,
            'current_run_id' => $turn->current_run_id,
            'started_at' => $turn->started_at?->toIso8601String(),
            'finished_at' => $turn->finished_at?->toIso8601String(),
            'created_at' => $turn->created_at?->toIso8601String(),
            'event_count' => $turn->events()->count(),
        ];

        $this->turnEvents = $turn->events()
            ->orderBy('seq')
            ->get()
            ->map(fn ($event): array => [
                'seq' => $event->seq,
                'event_type' => $event->event_type->value,
                'payload' => $event->payload,
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->all();
    }

    // ---------------------------------------------------------------
    // Lifecycle Control actions
    // ---------------------------------------------------------------

    /**
     * Preview the selected lifecycle action.
     */
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

        $service = app(LifecycleControlService::class);
        $this->lifecyclePreview = $this->mapLifecyclePreview($service->preview($action, $this->buildLifecycleScope($action)));
    }

    /**
     * Execute the selected lifecycle action.
     */
    public function executeLifecycleAction(): void
    {
        $this->lifecycleResult = null;
        $this->lifecycleError = '';

        $action = $this->resolveLifecycleAction();

        if ($action === null) {
            $this->lifecycleError = __('Select a lifecycle action.');

            return;
        }

        $service = app(LifecycleControlService::class);
        $this->lifecycleResult = $this->mapLifecycleRequest($service->execute(
            $action,
            $this->buildLifecycleScope($action),
            requestedBy: auth()->id(),
        ));

        // Refresh recent requests
        $this->loadRecentLifecycleRequests();
    }

    /**
     * Load recent lifecycle requests.
     */
    public function loadRecentLifecycleRequests(): void
    {
        $service = app(LifecycleControlService::class);
        $this->recentLifecycleRequests = array_map(fn (LifecycleRequestDTO $request): array => $this->mapLifecycleRequest($request), $service->recent(10));
    }

    // ---------------------------------------------------------------
    // Render
    // ---------------------------------------------------------------

    public function render(): View
    {
        return view('livewire.admin.ai.control-plane', [
            'runTranscript' => $this->loadRunTranscript(),
        ]);
    }

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    private function resetRunInspection(): void
    {
        $this->runInspections = [];
        $this->singleRunInspection = null;
        $this->inspectionError = '';
    }

    /**
     * Load transcript entries for a run, filtered to that run's messages.
     *
     * Returned as view data (not a public property) because Message DTOs
     * contain DateTimeImmutable which Livewire cannot dehydrate.
     *
     * @return list<Message>
     */
    private function loadRunTranscript(): array
    {
        if ($this->inspectRunId === '' || $this->singleRunInspection === null) {
            return [];
        }

        $run = AiRun::query()->find($this->inspectRunId);
        if ($run === null || $run->session_id === null) {
            return [];
        }

        $messageManager = app(MessageManager::class);
        $allMessages = $messageManager->read($run->employee_id, $run->session_id);
        $runId = $this->inspectRunId;

        return array_values(array_filter(
            $allMessages,
            fn (Message $msg) => $msg->runId === $runId
                || $msg->type === 'thinking'
                || $msg->type === 'tool_call'
                || $msg->type === 'tool_result',
        ));
    }

    private function resolveLifecycleAction(): ?LifecycleAction
    {
        if ($this->lifecycleAction === '') {
            return null;
        }

        return LifecycleAction::tryFrom($this->lifecycleAction);
    }

    /**
     * Build scope array from the current lifecycle form state.
     *
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
        };
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
