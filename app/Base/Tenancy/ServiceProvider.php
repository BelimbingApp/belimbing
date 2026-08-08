<?php

namespace App\Base\Tenancy;

use App\Base\Menu\Services\MenuConditionRegistry;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Models\Tenant;
use App\Base\Tenancy\Services\ApplicationTenantContext;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register services.
     *
     * Tenant context is a scoped binding: Octane/FrankenPHP workers flush
     * scoped instances between requests, jobs, and commands, so context
     * from one execution can never leak into the next.
     */
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, ApplicationTenantContext::class);
    }

    /**
     * Boot services.
     */
    public function boot(): void
    {
        $this->propagateTenantContextThroughQueue();
        $this->registerMenuVisibilityCondition();
    }

    /**
     * Latent tenancy: tenant management surfaces only when a second tenant
     * exists or the operator explicitly enables it via tenancy.show_management.
     */
    private function registerMenuVisibilityCondition(): void
    {
        $this->app->afterResolving(MenuConditionRegistry::class, function (MenuConditionRegistry $registry): void {
            $registry->register('tenancy.visible', static fn (mixed $user): bool => self::tenancySurfaceVisible());
        });
    }

    private static function tenancySurfaceVisible(): bool
    {
        try {
            if (Tenant::query()->count() > 1) {
                return true;
            }

            return (bool) app(SettingsService::class)->get('tenancy.show_management');
        } catch (\Throwable) {
            // Pre-migration or pre-seed: keep the surface hidden.
            return false;
        }
    }

    /**
     * Carry the dispatch-time tenant context into queued jobs.
     *
     * The tenant ID is stamped onto the queue payload at dispatch and the
     * worker restores it before the job runs, then clears it afterwards so
     * sequential jobs in the same worker process never share context.
     */
    private function propagateTenantContextThroughQueue(): void
    {
        Queue::createPayloadUsing(function (): array {
            $tenantId = $this->app->make(TenantContext::class)->currentTenantId();

            return $tenantId === null ? [] : ['tenantId' => $tenantId];
        });

        $events = $this->app['events'];

        $events->listen(JobProcessing::class, function (JobProcessing $event): void {
            $tenantId = $event->job->payload()['tenantId'] ?? null;

            $this->app->make(TenantContext::class)->set($tenantId !== null ? (int) $tenantId : null);
        });

        $clear = fn (): null => $this->app->make(TenantContext::class)->clear();

        $events->listen(JobProcessed::class, $clear);
        $events->listen(JobFailed::class, $clear);
        // A job that throws but has attempts left is released back to the
        // queue: neither JobProcessed nor JobFailed fires, so without this the
        // worker would carry that job's tenant into its idle loop and into
        // shutdown handlers.
        $events->listen(JobExceptionOccurred::class, $clear);
    }
}
