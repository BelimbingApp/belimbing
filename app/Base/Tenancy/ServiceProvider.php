<?php

namespace App\Base\Tenancy;

use App\Base\Menu\Services\MenuConditionRegistry;
use App\Base\Settings\Contracts\SettingsService;
use App\Base\Tenancy\Console\Commands\DomainCommandsCommand;
use App\Base\Tenancy\Console\Commands\TenantMissesCommand;
use App\Base\Tenancy\Contracts\TenantContext;
use App\Base\Tenancy\Exceptions\TenantInactiveException;
use App\Base\Tenancy\Models\Tenant;
use App\Base\Tenancy\Services\ApplicationTenantContext;
use App\Base\Tenancy\Services\PlatformOperatorTenantAccess;
use App\Base\Tenancy\Services\TenantContextMissRecorder;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    private const string MENU_VISIBILITY_ATTRIBUTE = 'blb.tenancy.menu_visible';

    /**
     * Register services.
     *
     * Tenant context is a scoped binding: Octane/FrankenPHP workers flush
     * scoped instances between requests, jobs, and commands, so context
     * from one execution can never leak into the next.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/domain_commands.php', 'domain_commands');
        $this->app->scoped(TenantContext::class, ApplicationTenantContext::class);
        $this->app->singleton(TenantContextMissRecorder::class);
        $this->commands([DomainCommandsCommand::class, TenantMissesCommand::class]);
    }

    /**
     * Boot services.
     */
    public function boot(): void
    {
        $this->propagateTenantContextThroughQueue();
        $this->registerMenuVisibilityConditions();
    }

    /**
     * Latent tenancy: tenant management surfaces only when a second tenant
     * exists or the operator explicitly enables it via tenancy.show_management.
     */
    private function registerMenuVisibilityConditions(): void
    {
        $this->app->afterResolving(MenuConditionRegistry::class, function (MenuConditionRegistry $registry): void {
            $registry->register('tenancy.visible', static fn (mixed $user): bool => self::tenancySurfaceVisible());
            $registry->register(
                'tenancy.platform_operator',
                static fn (mixed $user): bool => $user !== null
                    && app(PlatformOperatorTenantAccess::class)->allows(),
            );
        });
    }

    private static function tenancySurfaceVisible(): bool
    {
        $request = request();

        if ($request->attributes->has(self::MENU_VISIBILITY_ATTRIBUTE)) {
            return (bool) $request->attributes->get(self::MENU_VISIBILITY_ATTRIBUTE);
        }

        try {
            $visible = Tenant::query()->count() > 1
                || (bool) app(SettingsService::class)->get('tenancy.show_management');
        } catch (\Throwable) {
            // Pre-migration or pre-seed: keep the surface hidden.
            $visible = false;
        }

        $request->attributes->set(self::MENU_VISIBILITY_ATTRIBUTE, $visible);

        return $visible;
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
            $stamped = $event->job->payload()['tenantId'] ?? null;
            $tenantId = $stamped !== null ? (int) $stamped : null;
            $context = $this->app->make(TenantContext::class);

            // A tenant the operator suspended must not keep draining its
            // backlog. An ID with no row is left alone: that is the unknown
            // tenant, not an inactive one.
            $tenant = $tenantId === null ? null : Tenant::withTrashed()->find($tenantId);

            if ($tenant !== null && ! $tenant->isActive()) {
                $context->clear();

                // fail(), not release(): a suspended tenant's job must not come
                // back on the next tick. Failing here deletes the job, and
                // Worker::process checks isDeleted() before it calls fire(), so
                // handle() never runs.
                $event->job->fail(new TenantInactiveException($tenantId, (string) $tenant->status));

                return;
            }

            $context->set($tenantId);
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
