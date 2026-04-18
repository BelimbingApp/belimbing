<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Audit;

use App\Base\Audit\DTO\RequestContext;
use App\Base\Audit\Listeners\AuthListener;
use App\Base\Audit\Listeners\CommandListener;
use App\Base\Audit\Listeners\JobListener;
use App\Base\Audit\Listeners\MutationListener;
use App\Base\Audit\Middleware\AuditRequestMiddleware;
use App\Base\Audit\Services\AuditBuffer;
use App\Base\Authz\DTO\Actor;
use App\Base\Authz\Enums\PrincipalType;
use App\Base\Authz\Models\PrincipalRole;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register Audit services.
     *
     * Merges config and binds the audit buffer and request context.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/Config/audit.php', 'audit');
        $this->discoverModuleAuditConfigs();

        $this->app->singleton(AuditBuffer::class);

        $this->app->singleton(RequestContext::class, function (): RequestContext {
            if ($this->app->runningInConsole()) {
                return $this->resolveConsoleContext();
            }

            return RequestContext::fromRequest($this->resolveCurrentActor());
        });
    }

    /**
     * Bootstrap audit event listeners and middleware.
     */
    public function boot(): void
    {
        $this->registerMutationListeners();
        $this->registerAuthListeners();
        $this->registerCommandListeners();
        $this->registerJobListeners();

        $this->app['router']->pushMiddlewareToGroup('web', AuditRequestMiddleware::class);
    }

    /**
     * Register global Eloquent mutation listeners.
     */
    private function registerMutationListeners(): void
    {
        $listener = $this->app->make(MutationListener::class);

        Event::listen('eloquent.created: *', [$listener, 'handle']);
        Event::listen('eloquent.updated: *', [$listener, 'handle']);
        Event::listen('eloquent.deleted: *', [$listener, 'handle']);
    }

    /**
     * Register auth event listeners when enabled.
     */
    private function registerAuthListeners(): void
    {
        if (! config('audit.log_auth_events', true)) {
            return;
        }

        Event::listen(Login::class, [AuthListener::class, 'handleLogin']);
        Event::listen(Logout::class, [AuthListener::class, 'handleLogout']);
        Event::listen(Failed::class, [AuthListener::class, 'handleFailed']);
    }

    /**
     * Register console command listener when enabled.
     */
    private function registerCommandListeners(): void
    {
        if (! config('audit.log_console_commands', true)) {
            return;
        }

        Event::listen(CommandFinished::class, CommandListener::class);
    }

    /**
     * Register queue job listeners when enabled.
     */
    private function registerJobListeners(): void
    {
        if (! config('audit.log_queue_jobs', true)) {
            return;
        }

        Event::listen(JobProcessed::class, [JobListener::class, 'handleProcessed']);
        Event::listen(JobFailed::class, [JobListener::class, 'handleFailed']);
    }

    /**
     * Discover and merge module audit configs into the aggregated config.
     *
     * Scans Base and Module directories for Config/audit.php files,
     * merging their exclude_models into the main audit config.
     */
    private function discoverModuleAuditConfigs(): void
    {
        $config = $this->app->make('config');
        $basePath = realpath(__DIR__.'/Config/audit.php');

        $patterns = [
            app_path('Base/*/Config/audit.php'),
            app_path('Modules/*/*/Config/audit.php'),
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $file) {
                if (realpath($file) === $basePath) {
                    continue;
                }

                $moduleConfig = require $file;

                if (isset($moduleConfig['exclude_models'])) {
                    $config->set('audit.exclude_models', array_merge(
                        $config->get('audit.exclude_models', []),
                        $moduleConfig['exclude_models']
                    ));
                }
            }
        }
    }

    /**
     * Build request context for CLI execution.
     *
     * Detects whether the process is a scheduler tick or a plain
     * artisan command and uses the appropriate factory method.
     */
    private function resolveConsoleContext(): RequestContext
    {
        $argv = $_SERVER['argv'] ?? [];
        $command = implode(' ', array_slice($argv, 1));
        $actor = $this->resolveCurrentActor();

        if ($this->isSchedulerProcess($argv)) {
            return RequestContext::forScheduler($command !== '' ? $command : null);
        }

        return RequestContext::forConsole($actor, $command !== '' ? $command : null);
    }

    /**
     * Resolve the current actor from the authenticated user, if available.
     *
     * Loads the user's current role names so they can be recorded
     * in audit entries as a point-in-time snapshot.
     */
    private function resolveCurrentActor(): ?Actor
    {
        $user = auth()->user();

        if ($user === null) {
            return null;
        }

        $roleNames = PrincipalRole::query()
            ->join('base_authz_roles', 'base_authz_roles.id', '=', 'base_authz_principal_roles.role_id')
            ->where('base_authz_principal_roles.principal_type', PrincipalType::USER->value)
            ->where('base_authz_principal_roles.principal_id', $user->getAuthIdentifier())
            ->pluck('base_authz_roles.code')
            ->sort()
            ->implode(',');

        return Actor::forUser($user, PrincipalType::USER, attributes: [
            'role' => $roleNames !== '' ? $roleNames : null,
        ]);
    }

    /**
     * Check if the current process is a scheduler invocation.
     *
     * @param  array<int, string>  $argv
     */
    private function isSchedulerProcess(array $argv): bool
    {
        foreach ($argv as $arg) {
            if (str_contains($arg, 'schedule:run') || str_contains($arg, 'schedule:work')) {
                return true;
            }
        }

        return false;
    }
}
