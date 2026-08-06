<?php

namespace App\Base\Queue;

use App\Base\Database\Contracts\DevelopmentSanitizationContributor;
use App\Base\Queue\Services\PendingQueueDevelopmentSanitizer;
use App\Base\Queue\Services\QueueFailureRateMonitor;
use App\Base\Queue\Services\QueueStatusDiagnosticProvider;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QueueFailureRateMonitor::class);
        $this->app->singleton(QueueStatusDiagnosticProvider::class);
        $this->app->tag(QueueStatusDiagnosticProvider::class, StatusBarDiagnosticProvider::CONTAINER_TAG);
        $this->app->tag(PendingQueueDevelopmentSanitizer::class, DevelopmentSanitizationContributor::CONTAINER_TAG);
    }

    /**
     * Feed the rolling failure-rate counter behind the queue status-bar
     * diagnostic, and log both individual failures and sustained bad runs.
     */
    public function boot(QueueFailureRateMonitor $failureRate): void
    {
        Queue::failing(function (JobFailed $event) use ($failureRate): void {
            $failures = $failureRate->record();

            Log::warning('Queue job failed', [
                'job' => $event->job->getName(),
                'exception' => $event->exception->getMessage(),
            ]);

            if ($failureRate->exceedsThreshold($failures)) {
                Log::error('High queue failure rate detected', [
                    'failures' => $failures,
                ]);
            }
        });

        Queue::after(fn () => $failureRate->drain());
    }
}
