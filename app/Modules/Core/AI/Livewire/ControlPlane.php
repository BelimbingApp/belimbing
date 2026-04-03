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
use App\Modules\Core\AI\Enums\LifecycleAction;
use App\Modules\Core\AI\Services\ControlPlane\HealthAndPresenceService;
use App\Modules\Core\AI\Services\ControlPlane\LifecycleControlService;
use App\Modules\Core\AI\Services\ControlPlane\RunInspectionService;
use Illuminate\Contracts\View\View;
use Livewire\Component;

class ControlPlane extends Component
{
    // -- Run Inspector state --

    public string $inspectRunId = '';

    public string $inspectSessionId = '';

    public int $inspectEmployeeId = 0;

    /** @var list<RunInspection> */
    public array $runInspections = [];

    public ?RunInspection $singleRunInspection = null;

    public string $inspectionError = '';

    // -- Health & Presence state --

    /** @var list<HealthSnapshot> */
    public array $toolSnapshots = [];

    public ?HealthSnapshot $agentSnapshot = null;

    public int $healthAgentId = 0;

    // -- Lifecycle state --

    public string $lifecycleAction = '';

    public int $lifecycleEmployeeId = 0;

    public int $lifecycleRetentionDays = 30;

    public int $lifecycleStaleMinutes = 30;

    public string $lifecycleSessionId = '';

    public ?LifecyclePreview $lifecyclePreview = null;

    public ?LifecycleRequestDTO $lifecycleResult = null;

    public string $lifecycleError = '';

    /** @var list<LifecycleRequestDTO> */
    public array $recentLifecycleRequests = [];

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

        $this->singleRunInspection = $result;
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

        $this->runInspections = $results;
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
        $this->toolSnapshots = $service->allToolSnapshots();
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
        $this->agentSnapshot = $service->agentSnapshot($this->healthAgentId);
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
        $this->lifecyclePreview = $service->preview($action, $this->buildLifecycleScope($action));
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
        $this->lifecycleResult = $service->execute(
            $action,
            $this->buildLifecycleScope($action),
            requestedBy: auth()->id(),
        );

        // Refresh recent requests
        $this->loadRecentLifecycleRequests();
    }

    /**
     * Load recent lifecycle requests.
     */
    public function loadRecentLifecycleRequests(): void
    {
        $service = app(LifecycleControlService::class);
        $this->recentLifecycleRequests = $service->recent(10);
    }

    // ---------------------------------------------------------------
    // Render
    // ---------------------------------------------------------------

    public function render(): View
    {
        return view('livewire.admin.ai.control-plane');
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
}
