<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Authz;

use App\Base\Authz\Capability\CapabilityCatalog;
use App\Base\Authz\Capability\CapabilityRegistry;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\Contracts\DecisionLogger;
use App\Base\Authz\Policies\ActorContextPolicy;
use App\Base\Authz\Policies\CompanyScopePolicy;
use App\Base\Authz\Policies\GrantPolicy;
use App\Base\Authz\Policies\KnownCapabilityPolicy;
use App\Base\Authz\Services\AuditingAuthorizationService;
use App\Base\Authz\Services\AuthorizationEngine;
use App\Base\Authz\Services\AuthzMenuAccessChecker;
use App\Base\Authz\Services\DatabaseDecisionLogger;
use App\Base\Authz\Services\ImpersonationManager;
use App\Base\Menu\Contracts\MenuAccessChecker;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register services.
     *
     * Merges base config, discovers module authz configs,
     * and wires the policy pipeline with auditing decorator.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/authz.php', 'authz');
        $this->discoverModuleAuthzConfigs();

        $this->app->singleton(CapabilityCatalog::class, function (): CapabilityCatalog {
            /** @var array<string, mixed> $config */
            $config = config('authz');

            return CapabilityCatalog::fromConfig($config);
        });

        $this->app->singleton(CapabilityRegistry::class, function ($app): CapabilityRegistry {
            $catalog = $app->make(CapabilityCatalog::class);

            return CapabilityRegistry::fromCatalog($catalog);
        });

        $this->app->singleton(GrantPolicy::class);

        $this->app->singleton(AuthorizationEngine::class, function ($app): AuthorizationEngine {
            return new AuthorizationEngine([
                new ActorContextPolicy,
                new KnownCapabilityPolicy($app->make(CapabilityRegistry::class)),
                new CompanyScopePolicy,
                $app->make(GrantPolicy::class),
            ]);
        });

        $this->app->singleton(DecisionLogger::class, DatabaseDecisionLogger::class);

        $this->app->singleton(AuthorizationService::class, AuditingAuthorizationService::class);
        $this->app->singleton(MenuAccessChecker::class, AuthzMenuAccessChecker::class);

        $this->app->singleton(ImpersonationManager::class);
    }

    /**
     * Discover and merge module authz configs into the aggregated config.
     *
     * Scans Base and Module directories for Config/authz.php files,
     * merging their capabilities and roles into the main authz config.
     */
    private function discoverModuleAuthzConfigs(): void
    {
        $config = $this->app->make('config');
        $basePath = realpath(__DIR__.'/Config/authz.php');

        $patterns = [
            app_path('Base/*/Config/authz.php'),
            app_path('Modules/*/*/Config/authz.php'),
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (realpath($file) === $basePath) {
                    continue;
                }

                $moduleConfig = require $file;

                if (isset($moduleConfig['capabilities'])) {
                    $config->set('authz.capabilities', array_merge(
                        $config->get('authz.capabilities', []),
                        $moduleConfig['capabilities']
                    ));
                }

                if (isset($moduleConfig['roles'])) {
                    $config->set('authz.roles', array_merge(
                        $config->get('authz.roles', []),
                        $moduleConfig['roles']
                    ));
                }
            }
        }
    }
}
