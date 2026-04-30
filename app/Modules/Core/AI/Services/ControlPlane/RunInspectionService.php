<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Modules\Core\AI\DTO\ControlPlane\RunInspection;
use App\Modules\Core\AI\Models\AiRun;

/**
 * Assembles coherent run inspections from the ai_runs ledger.
 *
 * Provides one normalized view of a run instead of forcing operators to
 * correlate fragments from logs, session files, and dispatch tables.
 * Raw content and secrets are never exposed.
 */
class RunInspectionService
{
    /**
     * Inspect a single run by ID.
     *
     * @param  string  $runId  Run identifier
     */
    public function inspectRun(string $runId): ?RunInspection
    {
        $run = AiRun::query()->with(['actingForUser', 'employee'])->find($runId);

        if ($run === null) {
            return null;
        }

        return RunInspection::fromAiRun($run);
    }

    /**
     * Inspect all runs within a session.
     *
     * Returns runs ordered by started_at ascending (timeline order).
     *
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session identifier
     * @return list<RunInspection>
     */
    public function inspectSession(int $employeeId, string $sessionId): array
    {
        $runs = AiRun::query()
            ->with(['actingForUser', 'employee'])
            ->where('employee_id', $employeeId)
            ->where('session_id', $sessionId)
            ->orderBy('started_at')
            ->get();

        return $runs->map(fn (AiRun $run) => RunInspection::fromAiRun($run))->all();
    }

    /**
     * Inspect runs linked to an operation dispatch.
     *
     * @param  string  $dispatchId  Operation dispatch ID
     * @return list<RunInspection>
     */
    public function inspectDispatchRun(string $dispatchId): array
    {
        $runs = AiRun::query()
            ->with(['actingForUser', 'employee'])
            ->where('dispatch_id', $dispatchId)
            ->orderBy('started_at')
            ->get();

        return $runs->map(fn (AiRun $run) => RunInspection::fromAiRun($run))->all();
    }
}
