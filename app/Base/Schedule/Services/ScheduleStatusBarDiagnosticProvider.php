<?php

namespace App\Base\Schedule\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Enums\StatusVariant;
use App\Base\Schedule\Models\ScheduleRun;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ScheduleStatusBarDiagnosticProvider implements StatusBarDiagnosticProvider
{
    private const int RECENT_ACTIVITY_MINUTES = 15;

    public function __construct(private readonly AuthorizationService $authorizationService) {}

    public function diagnosticsFor(Authenticatable $user): iterable
    {
        try {
            if (! $this->authorizationService->can(Actor::forUser($user), 'admin.system.schedule.view')->allowed
                || ! Schema::hasTable('base_schedule_runs')) {
                return [];
            }

            $lastRecordedActivity = ScheduleRun::query()->max('started_at');
            if ($lastRecordedActivity === null
                || now()->subMinutes(self::RECENT_ACTIVITY_MINUTES)->lt($lastRecordedActivity)) {
                return [];
            }
        } catch (Throwable) {
            return [];
        }

        return [new StatusBarDiagnostic(
            id: 'schedule.no-recent-recorded-activity',
            severity: StatusVariant::Warning,
            source: __('Schedule'),
            summary: __('No recent scheduled activity was recorded'),
            detail: __('No scheduled run has been recorded in the last :minutes minutes. This can mean the scheduler, recorder, or database path needs attention.', ['minutes' => self::RECENT_ACTIVITY_MINUTES]),
            target: Route::has('admin.system.schedule.index') ? route('admin.system.schedule.index') : null,
            metadata: ['activity_window_minutes' => self::RECENT_ACTIVITY_MINUTES],
        )];
    }
}
