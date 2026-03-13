<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database;

use App\Base\Database\Console\Commands\FreshCommand;
use App\Base\Database\Console\Commands\MigrateCommand;
use App\Base\Database\Console\Commands\RefreshCommand;
use App\Base\Database\Console\Commands\ResetCommand;
use App\Base\Database\Console\Commands\RollbackCommand;
use App\Base\Database\Console\Commands\StatusCommand;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Console\Migrations\FreshCommand as LaravelFreshCommand;
use Illuminate\Database\Console\Migrations\MigrateCommand as LaravelMigrateCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand as LaravelRefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand as LaravelResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand as LaravelRollbackCommand;
use Illuminate\Database\Console\Migrations\StatusCommand as LaravelStatusCommand;
use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Override Laravel's MigrateCommand by extending the binding
        // Laravel's MigrationServiceProvider (deferred) binds MigrateCommand::class directly,
        // so we extend the class name, not an alias. The extend() callback runs when
        // the binding is resolved, after Laravel's MigrationServiceProvider registers it.
        $this->app->extend(LaravelMigrateCommand::class, fn ($app) => new MigrateCommand(
            $app->make(Migrator::class),
            $app->make(Dispatcher::class)
        ));

        $this->app->extend(LaravelRollbackCommand::class, fn ($app) => new RollbackCommand(
            $app->make(Migrator::class)
        ));

        $this->app->extend(LaravelStatusCommand::class, fn ($app) => new StatusCommand(
            $app->make(Migrator::class)
        ));

        $this->app->extend(LaravelResetCommand::class, fn ($app) => new ResetCommand(
            $app->make(Migrator::class)
        ));

        $this->app->extend(LaravelRefreshCommand::class, fn () => new RefreshCommand);

        $this->app->extend(LaravelFreshCommand::class, fn ($app) => new FreshCommand(
            $app->make(Migrator::class)
        ));
    }
}
