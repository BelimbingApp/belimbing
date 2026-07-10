<?php

namespace App\Modules\Core\AI\Services\Scheduling;

use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Jobs\RunAgentTaskJob;
use App\Modules\Core\AI\Jobs\RunHeadlessCliTaskJob;
use App\Modules\Core\AI\Models\OperationDispatch;
use App\Modules\Core\AI\Models\ScheduleDefinition;
use App\Modules\Core\Employee\Models\Employee;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Finds due schedule definitions and dispatches execution work.
 *
 * Called by the DispatchDueSchedulesJob or the scheduler tick command.
 * Queries for enabled schedules whose next_due_at has passed, respects
 * concurrency policies, creates OperationDispatch records, and queues
 * jobs for execution.
 *
 * Key invariant: schedules are replay-safe. If the planner runs after
 * downtime, it fires due schedules exactly once per missed interval
 * (not once per missed tick).
 */
class SchedulePlanner
{
    /**
     * Find and dispatch all due schedules.
     *
     * @param  Carbon|null  $asOf  Reference time (defaults to now)
     * @return int Number of schedules dispatched
     */
    public function dispatchDue(?Carbon $asOf = null, ?string $source = null): int
    {
        $now = $asOf ?? now();

        $dueSchedules = $this->findDue($now, $source);
        $dispatched = 0;

        foreach ($dueSchedules as $schedule) {
            if ($schedule->shouldSkipForConcurrency()) {
                Log::info('Schedule skipped due to concurrency policy.', [
                    'schedule_id' => $schedule->id,
                    'source' => $schedule->source,
                    'source_key' => $schedule->source_key,
                    'description' => $schedule->description,
                    'policy' => $schedule->concurrency_policy,
                ]);

                // Still advance next_due_at to prevent re-firing on next tick
                $schedule->recordFired();

                continue;
            }

            $dispatch = $this->createDispatch($schedule);
            $this->queueExecution($dispatch, $schedule);
            $schedule->recordFired();
            $dispatched++;

            Log::info('Schedule dispatched.', [
                'schedule_id' => $schedule->id,
                'dispatch_id' => $dispatch->id,
                'source' => $schedule->source,
                'source_key' => $schedule->source_key,
                'executor' => $schedule->executor,
                'description' => $schedule->description,
            ]);
        }

        return $dispatched;
    }

    /**
     * Find all enabled schedules whose next_due_at has passed or whose
     * manual run request is pending.
     *
     * @param  Carbon  $asOf  Reference time
     * @return Collection<int, ScheduleDefinition>
     */
    public function findDue(Carbon $asOf, ?string $source = null): Collection
    {
        $query = ScheduleDefinition::query()
            ->where('is_enabled', true)
            ->where(function ($query) use ($asOf): void {
                $query
                    ->whereNotNull('run_requested_at')
                    ->orWhere(function ($query) use ($asOf): void {
                        $query->whereNotNull('next_due_at')
                            ->where('next_due_at', '<=', $asOf);
                    });
            })
            ->orderByRaw('run_requested_at is null')
            ->orderBy('next_due_at');

        if ($source !== null) {
            $query->where('source', $source);
        }

        return $query->get();
    }

    /**
     * Create an OperationDispatch record for a scheduled task.
     */
    private function createDispatch(ScheduleDefinition $schedule): OperationDispatch
    {
        $employeeId = $this->executionEmployeeId($schedule);

        return OperationDispatch::query()->create([
            'id' => OperationDispatch::ID_PREFIX.Str::random(12),
            'operation_type' => $schedule->usesHeadlessCli()
                ? OperationType::HeadlessTask
                : OperationType::ScheduledTask,
            'employee_id' => $employeeId,
            'acting_for_user_id' => $schedule->created_by_user_id,
            'task' => $schedule->execution_payload,
            'status' => OperationStatus::Queued,
            'meta' => [
                'schedule_id' => $schedule->id,
                'schedule_description' => $schedule->description,
                'schedule_source' => $schedule->source,
                'schedule_source_key' => $schedule->source_key,
                'source' => $schedule->source,
                'source_key' => $schedule->source_key,
                'executor' => $schedule->executor,
                'cron_expression' => $schedule->cron_expression,
                'trigger' => $schedule->run_requested_at === null ? 'schedule' : 'manual',
                'headless_provider' => $schedule->headless_provider,
                'headless_model' => $schedule->headless_model,
            ],
        ]);
    }

    /**
     * Queue the execution job for a scheduled dispatch.
     *
     * Scheduled tasks route to their declared executor.
     */
    private function queueExecution(OperationDispatch $dispatch, ScheduleDefinition $schedule): void
    {
        if ($schedule->usesHeadlessCli()) {
            RunHeadlessCliTaskJob::dispatch($dispatch->id);

            return;
        }

        if ($schedule->employee_id !== null) {
            RunAgentTaskJob::dispatch($dispatch->id);
        } else {
            // Non-agent scheduled tasks (commands, workflows) are not yet supported.
            // Mark as failed with an informative message.
            $dispatch->markFailed('Schedule does not target an agent. Non-agent execution is not yet supported.');
        }
    }

    private function executionEmployeeId(ScheduleDefinition $schedule): ?int
    {
        if ($schedule->employee_id !== null) {
            return $schedule->employee_id;
        }

        if ($schedule->usesHeadlessCli()) {
            return Employee::LARA_ID;
        }

        return null;
    }
}
