<?php

namespace App\Base\Queue\Services;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Foundation\Enums\StatusVariant;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\DTO\StatusBarDiagnostic;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

final class QueueStatusDiagnosticProvider implements StatusBarDiagnosticProvider
{
    private const HIGH_FAILURE_RATE_THRESHOLD = 10;

    public function __construct(
        private readonly AuthorizationService $authorizationService,
    ) {}

    /**
     * @return iterable<int, StatusBarDiagnostic>
     */
    public function diagnosticsFor(Authenticatable $user): iterable
    {
        if (! $this->canViewFailedJobs($user)) {
            return [];
        }

        $recentFailures = $this->recentFailureCount();
        $failedJobs = $this->failedJobCount();

        if ($recentFailures > self::HIGH_FAILURE_RATE_THRESHOLD) {
            return [$this->highFailureRateDiagnostic($recentFailures, $failedJobs)];
        }

        if (($failedJobs ?? 0) > 0) {
            return [$this->failedJobsDiagnostic($failedJobs)];
        }

        return [];
    }

    private function highFailureRateDiagnostic(int $recentFailures, ?int $failedJobs): StatusBarDiagnostic
    {
        return new StatusBarDiagnostic(
            id: 'queue.high-failure-rate',
            severity: StatusVariant::Error,
            source: __('Queue'),
            summary: __('High queue failure rate detected'),
            detail: __(':count queue failures were recorded recently. Open Failed Jobs to inspect, retry, or delete failed work.', [
                'count' => $recentFailures,
            ]),
            target: $this->failedJobsUrl(),
            metadata: [
                'recent_failures' => $recentFailures,
                'failed_jobs' => $failedJobs,
                'threshold' => self::HIGH_FAILURE_RATE_THRESHOLD,
            ],
        );
    }

    private function failedJobsDiagnostic(int $failedJobs): StatusBarDiagnostic
    {
        return new StatusBarDiagnostic(
            id: 'queue.failed-jobs',
            severity: StatusVariant::Warning,
            source: __('Queue'),
            summary: trans_choice(':count failed job needs attention|:count failed jobs need attention', $failedJobs, [
                'count' => $failedJobs,
            ]),
            detail: __('Open Failed Jobs to inspect, retry, or delete failed work.'),
            target: $this->failedJobsUrl(),
            metadata: [
                'failed_jobs' => $failedJobs,
            ],
        );
    }

    private function recentFailureCount(): int
    {
        try {
            return max(0, (int) Cache::get('queue_failures', 0));
        } catch (\Throwable) {
            return 0;
        }
    }

    private function failedJobCount(): ?int
    {
        try {
            if (! Schema::hasTable('failed_jobs')) {
                return null;
            }

            return (int) DB::table('failed_jobs')->count();
        } catch (\Throwable) {
            return null;
        }
    }

    private function failedJobsUrl(): ?string
    {
        return Route::has('admin.system.failed-jobs.index')
            ? route('admin.system.failed-jobs.index')
            : null;
    }

    private function canViewFailedJobs(Authenticatable $user): bool
    {
        try {
            return $this->authorizationService
                ->can(Actor::forUser($user), 'admin.system.failed-job.list')
                ->allowed;
        } catch (\Throwable) {
            return false;
        }
    }
}
