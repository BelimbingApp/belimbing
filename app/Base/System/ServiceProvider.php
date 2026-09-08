<?php

namespace App\Base\System;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Menu\Services\MenuConditionRegistry;
use App\Base\System\Console\Commands\KeyGenerateCommand;
use App\Base\System\Console\Commands\KeyRotateCommand;
use App\Base\System\Console\Commands\MutateCommand;
use App\Base\System\Console\Commands\PageWeightAuditCommand;
use App\Base\System\Console\Commands\SecurityCheckCommand;
use App\Base\System\Console\Commands\TestCommand;
use App\Base\System\Contracts\StatusBarDiagnosticProvider;
use App\Base\System\Services\ReportedErrorRecorder;
use App\Base\System\Services\ReportedErrorStatusDiagnosticProvider;
use App\Base\System\Services\RuntimeConfigurationApplier;
use App\Base\System\Services\StatusBarDiagnostics;
use App\Base\System\Services\SystemHealthProbe;
use App\Base\System\Services\SystemHealthStatusDiagnosticProvider;
use App\Base\System\Services\SystemOverviewPageData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Console\KeyGenerateCommand as LaravelKeyGenerateCommand;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;
use NunoMaduro\Collision\Adapters\Laravel\Commands\TestCommand as CollisionTestCommand;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        // Block the raw key:generate command — it would strand all backup DEKs.
        // Operators must use blb:key:rotate which re-wraps DEKs atomically.
        $this->app->extend(LaravelKeyGenerateCommand::class, fn () => new KeyGenerateCommand);
        $this->app->bind(CollisionTestCommand::class, TestCommand::class);
        $this->app->singleton(StatusBarDiagnostics::class);
        $this->app->singleton(SystemHealthProbe::class);
        $this->app->singleton(SystemHealthStatusDiagnosticProvider::class);
        $this->app->tag(SystemHealthStatusDiagnosticProvider::class, StatusBarDiagnosticProvider::CONTAINER_TAG);
        $this->app->singleton(ReportedErrorRecorder::class);
        $this->app->singleton(ReportedErrorStatusDiagnosticProvider::class);
        $this->app->singleton(RuntimeConfigurationApplier::class);
        $this->app->tag(ReportedErrorStatusDiagnosticProvider::class, StatusBarDiagnosticProvider::CONTAINER_TAG);
        $this->app->singleton(SystemOverviewPageData::class);

        $this->commands([
            KeyRotateCommand::class,
            MutateCommand::class,
            PageWeightAuditCommand::class,
            SecurityCheckCommand::class,
        ]);
    }

    public function boot(RuntimeConfigurationApplier $configuration): void
    {
        $configuration->apply();

        // Debug exception page (laravel-exceptions-renderer): use the BLB
        // favicon instead of Laravel's inline default.
        View::prependNamespace(
            'laravel-exceptions-renderer',
            resource_path('core/views/vendor/laravel-exceptions-renderer'),
        );

        $this->app->afterResolving(MenuConditionRegistry::class, function (MenuConditionRegistry $registry): void {
            $registry->register(
                'admin.system.overview.any',
                static function (Authenticatable $user): bool {
                    $actor = Actor::forUser($user);
                    $authz = app(AuthorizationService::class);

                    foreach (SystemOverviewPageData::VIEW_CAPABILITIES as $capability) {
                        if ($authz->can($actor, $capability)->allowed) {
                            return true;
                        }
                    }

                    return false;
                },
            );
        });
    }
}
