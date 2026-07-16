<?php

namespace App\Base\Workflow;

use App\Base\Workflow\Console\Commands\WorkflowAddKanbanColumnCommand;
use App\Base\Workflow\Console\Commands\WorkflowAddStatusCommand;
use App\Base\Workflow\Console\Commands\WorkflowAddTransitionCommand;
use App\Base\Workflow\Console\Commands\WorkflowCreateCommand;
use App\Base\Workflow\Console\Commands\WorkflowDescribeCommand;
use App\Base\Workflow\Console\Commands\WorkflowReconcileCommand;
use App\Base\Workflow\Console\Commands\WorkflowValidateCommand;
use App\Base\Workflow\Events\TransitionCompleted;
use App\Base\Workflow\Listeners\SendTransitionNotification;
use App\Base\Workflow\Process\Contracts\ProcessDefinitionContributor;
use App\Base\Workflow\Process\ProcessCoordinator;
use App\Base\Workflow\Process\ProcessDefinitionRegistry;
use App\Base\Workflow\Services\StatusManager;
use App\Base\Workflow\Services\TransitionManager;
use App\Base\Workflow\Services\TransitionOutbox;
use App\Base\Workflow\Services\TransitionOutboxDispatcher;
use App\Base\Workflow\Services\TransitionValidator;
use App\Base\Workflow\Services\WorkflowEngine;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register workflow engine services.
     */
    public function register(): void
    {
        $this->app->singleton(StatusManager::class);
        $this->app->singleton(TransitionManager::class);
        $this->app->singleton(TransitionValidator::class);
        $this->app->singleton(TransitionOutbox::class);
        $this->app->singleton(TransitionOutboxDispatcher::class);
        $this->app->singleton(ProcessDefinitionRegistry::class);
        $this->app->singleton(ProcessCoordinator::class);
        $this->app->singleton(WorkflowEngine::class);

        $this->app->booted(function (): void {
            $definitions = $this->app->make(ProcessDefinitionRegistry::class);

            foreach ($this->app->tagged(ProcessDefinitionContributor::CONTAINER_TAG) as $contributor) {
                $contributor->contribute($definitions);
            }

            $this->app->make(Schedule::class)
                ->command('blb:workflow:reconcile')
                ->everyMinute()
                ->withoutOverlapping(10);
        });
    }

    /**
     * Bootstrap workflow commands and event listeners.
     */
    public function boot(): void
    {
        Event::listen(TransitionCompleted::class, SendTransitionNotification::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                WorkflowCreateCommand::class,
                WorkflowAddStatusCommand::class,
                WorkflowAddTransitionCommand::class,
                WorkflowAddKanbanColumnCommand::class,
                WorkflowDescribeCommand::class,
                WorkflowValidateCommand::class,
                WorkflowReconcileCommand::class,
            ]);
        }
    }
}
