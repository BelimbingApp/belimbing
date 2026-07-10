<?php

namespace App\Base\Queue;

use App\Base\Database\Contracts\DevelopmentSanitizationContributor;
use App\Base\Queue\Services\PendingQueueDevelopmentSanitizer;
use App\Base\Queue\Services\QueueStatusDiagnosticProvider;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(QueueStatusDiagnosticProvider::class);
        $this->app->tag(QueueStatusDiagnosticProvider::class, StatusBarDiagnosticProvider::CONTAINER_TAG);
        $this->app->tag(PendingQueueDevelopmentSanitizer::class, DevelopmentSanitizationContributor::CONTAINER_TAG);
    }
}
