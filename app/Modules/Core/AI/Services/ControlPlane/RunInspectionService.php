<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Modules\Core\AI\DTO\ControlPlane\RunInspection;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Services\SessionManager;

/**
 * Assembles coherent run inspections from session metadata and dispatch records.
 *
 * Provides one normalized view of a run instead of forcing operators to
 * correlate fragments from logs, session files, and dispatch tables.
 * Raw content and secrets are never exposed.
 */
class RunInspectionService
{
    public function __construct(
        private readonly SessionManager $sessionManager,
    ) {}

    /**
     * Inspect a single run by ID within a session.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session identifier
     * @param  string  $runId  Run identifier
     */
    public function inspectRun(int $employeeId, string $sessionId, string $runId): ?RunInspection
    {
        $runData = $this->sessionManager->runMetadata($employeeId, $sessionId);

        if (! isset($runData[$runId])) {
            return null;
        }

        $entry = $runData[$runId];
        $meta = $entry['meta'] ?? [];
        $recordedAt = $entry['recorded_at'] ?? now()->toIso8601String();

        // Look for a linked dispatch by run_id
        $dispatch = OperationDispatch::query()
            ->where('run_id', $runId)
            ->first();

        return RunInspection::fromRunMeta(
            runId: $runId,
            employeeId: $employeeId,
            sessionId: $sessionId,
            meta: $meta,
            recordedAt: $recordedAt,
            dispatchId: $dispatch?->id,
        );
    }

    /**
     * Inspect all runs within a session.
     *
     * Returns runs ordered by recorded_at (oldest first) so operators
     * can follow the session timeline.
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session identifier
     * @return list<RunInspection>
     */
    public function inspectSession(int $employeeId, string $sessionId): array
    {
        $session = $this->sessionManager->get($employeeId, $sessionId);

        if ($session === null) {
            return [];
        }

        $runData = $session->runs;

        if ($runData === []) {
            return [];
        }

        // Pre-load dispatches linked to this session's runs
        $runIds = array_keys($runData);
        $dispatches = OperationDispatch::query()
            ->whereIn('run_id', $runIds)
            ->pluck('id', 'run_id')
            ->all();

        $inspections = [];

        foreach ($runData as $runId => $entry) {
            $meta = $entry['meta'] ?? [];
            $recordedAt = $entry['recorded_at'] ?? '';

            $inspections[] = RunInspection::fromRunMeta(
                runId: $runId,
                employeeId: $employeeId,
                sessionId: $sessionId,
                meta: $meta,
                recordedAt: $recordedAt,
                dispatchId: $dispatches[$runId] ?? null,
            );
        }

        // Sort by recordedAt ascending (timeline order)
        usort($inspections, fn (RunInspection $a, RunInspection $b) => $a->recordedAt <=> $b->recordedAt);

        return $inspections;
    }

    /**
     * Inspect a run linked to an operation dispatch.
     *
     * Locates the run through the dispatch record's run_id and agent context,
     * then finds the matching session. Returns null if the run cannot be located.
     */
    public function inspectDispatchRun(string $dispatchId): ?RunInspection
    {
        $dispatch = OperationDispatch::query()->find($dispatchId);

        if ($dispatch === null || $dispatch->run_id === null || $dispatch->employee_id === null) {
            return null;
        }

        // Search through the agent's sessions for the run
        $sessions = $this->sessionManager->list($dispatch->employee_id);

        foreach ($sessions as $session) {
            if (isset($session->runs[$dispatch->run_id])) {
                return $this->inspectRun(
                    $dispatch->employee_id,
                    $session->id,
                    $dispatch->run_id,
                );
            }
        }

        return null;
    }
}
