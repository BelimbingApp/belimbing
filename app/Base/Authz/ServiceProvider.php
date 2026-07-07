<?php

namespace App\Base\Authz;

use App\Base\Authz\Capability\CapabilityCatalog;
use App\Base\Authz\Capability\CapabilityRegistry;
use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\Contracts\DecisionLogger;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Policies\ActorContextPolicy;
use App\Base\Authz\Policies\CompanyScopePolicy;
use App\Base\Authz\Policies\GrantPolicy;
use App\Base\Authz\Policies\KnownCapabilityPolicy;
use App\Base\Authz\Services\AuditingAuthorizationService;
use App\Base\Authz\Services\AuthorizationEngine;
use App\Base\Authz\Services\AuthzMenuAccessChecker;
use App\Base\Authz\Services\DatabaseDecisionLogger;
use App\Base\Authz\Services\ImpersonationManager;
use App\Base\Foundation\Services\DomainState;
use App\Base\Menu\Contracts\MenuAccessChecker;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\Gate;
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
     * Wire BLB's AuthorizationService into Laravel's Gate.
     *
     * Blade views use auth()->user()->can($capability), which goes through the Gate.
     * Without this hook, all BLB capabilities return false from the Gate because no
     * policies are registered for them. By intercepting dot-notation abilities here,
     * grant_all roles (core_admin) and explicit grants work correctly in blade views.
     *
     * Only dot-notation strings are intercepted — native Gate abilities like viewAny,
     * create, update do not contain dots and fall through to Laravel's own handling.
     */
    public function boot(): void
    {
        Gate::before(function ($user, string $ability): ?bool {
            if (! str_contains($ability, '.')) {
                return null;
            }

            try {
                $allowed = app(AuthorizationService::class)
                    ->can(Actor::forUser($user), $ability)
                    ->allowed;

                return $allowed ? true : null;
            } catch (\Throwable) {
                return null;
            }
        });
    }

    /**
     * Discover and merge module authz configs into the aggregated config.
     *
     * Scans Base and Module directories for Config/authz.php files,
     * merging their domains, capabilities, and roles into the main authz config.
     */
    private function discoverModuleAuthzConfigs(): void
    {
        $config = $this->app->make('config');
        $basePath = realpath(__DIR__.'/Config/authz.php');

        $patterns = [
            app_path('Base/*/Config/authz.php'),
            app_path('Modules/*/*/Config/authz.php'),
            base_path('extensions/*/Config/authz.php'),
            base_path('extensions/*/*/Config/authz.php'),
        ];

        foreach ($patterns as $pattern) {
            foreach (DomainState::filterPaths(glob($pattern) ?: []) as $file) {
                $this->mergeAuthzConfigFile($file, $basePath, $config);
            }
        }
    }

    private function mergeAuthzConfigFile(string $file, string|false $basePath, Repository $config): void
    {
        if (is_string($basePath) && realpath($file) === $basePath) {
            return;
        }

        $moduleConfig = require $file;

        if (isset($moduleConfig['domains'])) {
            $config->set('authz.domains', array_merge(
                $config->get('authz.domains', []),
                $moduleConfig['domains']
            ));
        }

        if (isset($moduleConfig['capabilities'])) {
            $config->set('authz.capabilities', array_merge(
                $config->get('authz.capabilities', []),
                $moduleConfig['capabilities']
            ));
        }

        if (isset($moduleConfig['roles'])) {
            $roles = $config->get('authz.roles', []);

            foreach ($moduleConfig['roles'] as $roleCode => $roleDefinition) {
                if (isset($roles[$roleCode])) {
                    $roles[$roleCode] = $this->mergeRoleDefinition($roles[$roleCode], $roleDefinition);
                } else {
                    $roles[$roleCode] = $roleDefinition;
                }
            }

            $config->set('authz.roles', $roles);
        }
    }

    /**
     * Merge a module role definition into an existing system role.
     *
     * Modules may contribute additional capabilities to shared roles such as
     * tenant_owner without replacing the platform baseline definition.
     *
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $extension
     * @return array<string, mixed>
     */
    private function mergeRoleDefinition(array $base, array $extension): array
    {
        $merged = $base;

        foreach ($extension as $key => $value) {
            if ($key !== 'capabilities' && ! array_key_exists($key, $merged)) {
                $merged[$key] = $value;
            }
        }

        if (isset($base['capabilities'], $extension['capabilities'])
            && is_array($base['capabilities'])
            && is_array($extension['capabilities'])) {
            $merged['capabilities'] = array_values(array_unique([
                ...$base['capabilities'],
                ...$extension['capabilities'],
            ]));
        } elseif (isset($extension['capabilities']) && is_array($extension['capabilities'])) {
            $merged['capabilities'] = $extension['capabilities'];
        }

        return $merged;
    }
}
