<?php

namespace App\Base\Schedule\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Enums\StatusVariant;
use App\Base\Schedule\DTO\UnhealthyScheduleTask;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ScheduleStatusBarDiagnosticProvider implements StatusBarDiagnosticProvider
{
    private const int RECENT_ACTIVITY_MINUTES = 15;

    public function __construct(
        private readonly AuthorizationService $authorizationService,
        private readonly ScheduleHealthService $healthService,
    ) {}

    public function diagnosticsFor(Authenticatable $user): iterable
    {
        try {
            if (! $this->authorizationService->can(Actor::forUser($user), 'admin.system.schedule.view')->allowed
                || ! Schema::hasTable('base_schedule_runs')) {
                return [];
            }

            $diagnostics = [];

            // The health snapshot covers both the scheduler heartbeat
            // (last_recorded_at) and the failing-task projection in one
            // cached call, so the diagnostic provider no longer fans out a
            // separate max('started_at') query on every render.
            $snapshot = $this->healthService->snapshot();

            if ($snapshot['last_recorded_at'] !== null
                && now()->subMinutes(self::RECENT_ACTIVITY_MINUTES)->gte($snapshot['last_recorded_at'])) {
                $diagnostics[] = new StatusBarDiagnostic(
                    id: 'schedule.no-recent-recorded-activity',
                    severity: StatusVariant::Warning,
                    source: __('Schedule'),
                    summary: __('No recent scheduled activity was recorded'),
                    detail: __('No scheduled run has been recorded in the last :minutes minutes. This can mean the scheduler, recorder, or database path needs attention.', ['minutes' => self::RECENT_ACTIVITY_MINUTES]),
                    target: Route::has('admin.system.schedule.index') ? route('admin.system.schedule.index') : null,
                    metadata: ['activity_window_minutes' => self::RECENT_ACTIVITY_MINUTES],
                );
            }

            if ($snapshot['unhealthy'] !== []) {
                $diagnostics[] = $this->unhealthyTasksDiagnostic($snapshot['unhealthy']);
            }

            return $diagnostics;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param  list<UnhealthyScheduleTask>  $unhealthy
     */
    private function unhealthyTasksDiagnostic(array $unhealthy): StatusBarDiagnostic
    {
        $count = count($unhealthy);
        $escalated = collect($unhealthy)->contains(
            fn (UnhealthyScheduleTask $task): bool => $task->consecutiveFailures >= 2,
        );

        $names = collect($unhealthy)->map(fn (UnhealthyScheduleTask $task): string => $task->name)->all();

        $detail = collect($unhealthy)
            ->map(fn (UnhealthyScheduleTask $task): string => sprintf(
                '%s — %s',
                $task->name,
                $task->lastAttemptAt->diffForHumans(),
            ))
            ->implode('; ');

        return new StatusBarDiagnostic(
            id: 'schedule.failing-tasks',
            severity: $escalated ? StatusVariant::Error : StatusVariant::Warning,
            source: __('Schedule'),
            summary: trans_choice('{1} :count scheduled task failing|[2,*] :count scheduled tasks failing', $count, ['count' => $count]),
            detail: $detail,
            target: Route::has('admin.system.schedule.index') ? route('admin.system.schedule.index', ['tab' => 'history', 'status' => 'failed']) : null,
            metadata: ['failing_task_count' => $count, 'failing_task_names' => $names],
        );
    }
}
