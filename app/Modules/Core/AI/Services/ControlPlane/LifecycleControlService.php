<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Modules\Core\AI\DTO\ControlPlane\LifecyclePreview;
use App\Modules\Core\AI\DTO\ControlPlane\LifecycleRequest as LifecycleRequestDTO;
use App\Modules\Core\AI\Enums\LifecycleAction;
use App\Modules\Core\AI\Enums\LifecycleActionStatus;
use App\Modules\Core\AI\Enums\TelemetryEventType;
use App\Modules\Core\AI\Models\LifecycleRequest;
use App\Modules\Core\AI\Services\Browser\BrowserArtifactStore;
use App\Modules\Core\AI\Services\Browser\BrowserSessionManager;
use App\Modules\Core\AI\Services\Memory\MemoryCompactor;
use App\Modules\Core\AI\Services\OperationsDispatchService;
use App\Modules\Core\AI\Services\SessionManager;
use Illuminate\Support\Str;

/**
 * Manages lifecycle control operations: preview, execute, and audit.
 *
 * Every destructive or compaction operation goes through:
 * 1. Preview — show what would be affected
 * 2. Execute — perform the action (with status tracking)
 * 3. Audit — record the outcome in the lifecycle request ledger
 *
 * Operators can always see what happened and why.
 */
class LifecycleControlService
{
    public function __construct(
        private readonly MemoryCompactor $memoryCompactor,
        private readonly BrowserSessionManager $browserSessionManager,
        private readonly BrowserArtifactStore $browserArtifactStore,
        private readonly OperationsDispatchService $operationsDispatchService,
        private readonly SessionManager $sessionManager,
        private readonly OperationalTelemetryService $telemetry,
    ) {}

    /**
     * Preview what a lifecycle action would affect without executing it.
     *
     * @param  LifecycleAction  $action  The action to preview
     * @param  array<string, mixed>  $scope  Action-specific parameters
     */
    public function preview(LifecycleAction $action, array $scope = []): LifecyclePreview
    {
        return match ($action) {
            LifecycleAction::CompactMemory => $this->previewCompactMemory($scope),
            LifecycleAction::PruneSessions => $this->previewPruneSessions($scope),
            LifecycleAction::PruneArtifacts => $this->previewPruneArtifacts($scope),
            LifecycleAction::SweepBrowserSessions => $this->previewSweepBrowserSessions(),
            LifecycleAction::SweepOperations => $this->previewSweepOperations($scope),
        };
    }

    /**
     * Execute a lifecycle action with preview, audit, and status tracking.
     *
     * Creates a lifecycle request record, executes the action, and records
     * the outcome. Returns the completed request as a DTO.
     *
     * @param  LifecycleAction  $action  The action to execute
     * @param  array<string, mixed>  $scope  Action-specific parameters
     * @param  int|null  $requestedBy  User ID who initiated the request
     */
    public function execute(LifecycleAction $action, array $scope = [], ?int $requestedBy = null): LifecycleRequestDTO
    {
        $requestId = LifecycleRequest::ID_PREFIX.Str::ulid()->toBase32();
        $preview = $this->preview($action, $scope);

        // Create the request record
        $request = LifecycleRequest::query()->create([
            'id' => $requestId,
            'action' => $action,
            'scope' => $scope,
            'status' => LifecycleActionStatus::Previewed,
            'preview' => $preview->toArray(),
            'requested_by' => $requestedBy,
        ]);

        $request->markExecuting();

        try {
            $result = match ($action) {
                LifecycleAction::CompactMemory => $this->executeCompactMemory($scope),
                LifecycleAction::PruneSessions => $this->executePruneSessions($scope),
                LifecycleAction::PruneArtifacts => $this->executePruneArtifacts($scope),
                LifecycleAction::SweepBrowserSessions => $this->executeSweepBrowserSessions(),
                LifecycleAction::SweepOperations => $this->executeSweepOperations($scope),
            };

            $request->markCompleted($result);

            $this->telemetry->record(
                eventType: TelemetryEventType::LifecycleAction,
                payload: [
                    'action' => $action->value,
                    'scope' => $scope,
                    'result' => $result,
                    'status' => 'completed',
                ],
            );
        } catch (\Throwable $e) {
            $request->markFailed($e->getMessage());

            $this->telemetry->record(
                eventType: TelemetryEventType::LifecycleAction,
                payload: [
                    'action' => $action->value,
                    'scope' => $scope,
                    'status' => 'failed',
                    'error' => $e->getMessage(),
                ],
            );
        }

        $request->refresh();

        return $this->toDTO($request, $preview);
    }

    /**
     * Get recent lifecycle requests.
     *
     * @param  int  $limit  Maximum results
     * @return list<LifecycleRequestDTO>
     */
    public function recent(int $limit = 25): array
    {
        $requests = LifecycleRequest::query()
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        return $requests->map(fn (LifecycleRequest $r): LifecycleRequestDTO => $this->toDTO($r))->values()->all();
    }

    // -- Preview implementations --

    private function previewCompactMemory(array $scope): LifecyclePreview
    {
        $employeeId = (int) ($scope['employee_id'] ?? 0);
        $workspacePath = rtrim((string) config('ai.workspace_path'), '/').'/'.$employeeId;
        $dailyDir = $workspacePath.'/memory';
        $archivePrefix = (string) config('ai.memory.compaction_archive_prefix', 'archived-');

        $count = 0;
        $summary = [];

        if (is_dir($dailyDir)) {
            $files = @scandir($dailyDir) ?: [];

            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || ! str_ends_with($file, '.md')) {
                    continue;
                }

                if (! str_starts_with($file, $archivePrefix)) {
                    $count++;
                    $summary[] = "Daily note: {$file}";
                }
            }
        }

        return new LifecyclePreview(
            action: LifecycleAction::CompactMemory,
            scope: $scope,
            affectedCount: $count,
            affectedSummary: $count > 0
                ? $summary
                : ['No unarchived daily notes to compact.'],
            isDestructive: false,
            generatedAt: now()->toIso8601String(),
        );
    }

    private function previewPruneSessions(array $scope): LifecyclePreview
    {
        $employeeId = (int) ($scope['employee_id'] ?? 0);
        $retentionDays = (int) ($scope['retention_days'] ?? 30);
        $cutoff = now()->subDays($retentionDays);

        $sessions = $this->sessionManager->list($employeeId);
        $stale = [];

        foreach ($sessions as $session) {
            if ($session->lastActivityAt->getTimestamp() < $cutoff->getTimestamp()) {
                $stale[] = "Session {$session->id} (last active: {$session->lastActivityAt->format('Y-m-d H:i:s')})";
            }
        }

        return new LifecyclePreview(
            action: LifecycleAction::PruneSessions,
            scope: $scope,
            affectedCount: count($stale),
            affectedSummary: $stale !== [] ? $stale : ['No sessions older than '.$retentionDays.' days.'],
            isDestructive: true,
            generatedAt: now()->toIso8601String(),
        );
    }

    private function previewPruneArtifacts(array $scope): LifecyclePreview
    {
        $sessionId = $scope['session_id'] ?? null;

        if ($sessionId !== null) {
            $artifacts = $this->browserArtifactStore->listForSession($sessionId);
            $count = count($artifacts);
            $summary = $count > 0
                ? ["Session {$sessionId}: {$count} artifact(s) would be deleted."]
                : ["Session {$sessionId}: no artifacts found."];
        } else {
            // Without a specific session, report a general summary
            $count = 0;
            $summary = ['Specify a session_id to preview artifact pruning.'];
        }

        return new LifecyclePreview(
            action: LifecycleAction::PruneArtifacts,
            scope: $scope,
            affectedCount: $count,
            affectedSummary: $summary,
            isDestructive: true,
            generatedAt: now()->toIso8601String(),
        );
    }

    private function previewSweepBrowserSessions(): LifecyclePreview
    {
        // We report the availability — actual stale count requires repository access
        $available = $this->browserSessionManager->isAvailable();
        $summary = $available
            ? ['Browser sessions past TTL will be marked as expired.']
            : ['Browser automation is not available — no sessions to sweep.'];

        return new LifecyclePreview(
            action: LifecycleAction::SweepBrowserSessions,
            scope: [],
            affectedCount: 0,
            affectedSummary: $summary,
            isDestructive: false,
            generatedAt: now()->toIso8601String(),
        );
    }

    private function previewSweepOperations(array $scope): LifecyclePreview
    {
        $staleMinutes = (int) ($scope['stale_minutes'] ?? 30);
        $stale = $this->operationsDispatchService->findStale($staleMinutes);
        $count = $stale->count();

        $summary = $count > 0
            ? $stale->map(fn ($d) => "Dispatch {$d->id}: {$d->operation_type->label()} running since {$d->started_at}")->values()->all()
            : ["No operations stuck for more than {$staleMinutes} minutes."];

        return new LifecyclePreview(
            action: LifecycleAction::SweepOperations,
            scope: $scope,
            affectedCount: $count,
            affectedSummary: $summary,
            isDestructive: false,
            generatedAt: now()->toIso8601String(),
        );
    }

    // -- Execute implementations --

    /**
     * @return array<string, mixed>
     */
    private function executeCompactMemory(array $scope): array
    {
        $employeeId = (int) ($scope['employee_id'] ?? 0);

        return $this->memoryCompactor->compact($employeeId);
    }

    /**
     * @return array<string, mixed>
     */
    private function executePruneSessions(array $scope): array
    {
        $employeeId = (int) ($scope['employee_id'] ?? 0);
        $retentionDays = (int) ($scope['retention_days'] ?? 30);
        $cutoff = now()->subDays($retentionDays);

        $sessions = $this->sessionManager->list($employeeId);
        $pruned = 0;

        foreach ($sessions as $session) {
            if ($session->lastActivityAt->getTimestamp() < $cutoff->getTimestamp()) {
                $this->sessionManager->delete($employeeId, $session->id);
                $pruned++;
            }
        }

        return ['pruned_sessions' => $pruned];
    }

    /**
     * @return array<string, mixed>
     */
    private function executePruneArtifacts(array $scope): array
    {
        $sessionId = $scope['session_id'] ?? null;

        if ($sessionId === null) {
            return ['deleted_artifacts' => 0, 'note' => 'No session_id specified.'];
        }

        $deleted = $this->browserArtifactStore->deleteForSession($sessionId);

        return ['deleted_artifacts' => $deleted, 'session_id' => $sessionId];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeSweepBrowserSessions(): array
    {
        $swept = $this->browserSessionManager->sweepStaleSessions();

        return ['swept_sessions' => $swept];
    }

    /**
     * @return array<string, mixed>
     */
    private function executeSweepOperations(array $scope): array
    {
        $staleMinutes = (int) ($scope['stale_minutes'] ?? 30);
        $swept = $this->operationsDispatchService->sweepStale($staleMinutes);

        return ['swept_operations' => $swept, 'stale_minutes' => $staleMinutes];
    }

    /**
     * Convert a model to a DTO.
     */
    private function toDTO(LifecycleRequest $request, ?LifecyclePreview $preview = null): LifecycleRequestDTO
    {
        // Rebuild preview from stored JSON if not provided
        if ($preview === null && $request->preview !== null) {
            $p = $request->preview;
            $preview = new LifecyclePreview(
                action: $request->action,
                scope: $p['scope'] ?? [],
                affectedCount: $p['affected_count'] ?? 0,
                affectedSummary: $p['affected_summary'] ?? [],
                isDestructive: $p['is_destructive'] ?? false,
                generatedAt: $p['generated_at'] ?? '',
            );
        }

        return new LifecycleRequestDTO(
            requestId: $request->id,
            action: $request->action,
            scope: $request->scope ?? [],
            status: $request->status,
            preview: $preview,
            result: $request->result,
            errorMessage: $request->error_message,
            requestedBy: $request->requested_by,
            createdAt: $request->created_at?->toIso8601String() ?? '',
            executedAt: $request->executed_at?->toIso8601String(),
        );
    }
}
