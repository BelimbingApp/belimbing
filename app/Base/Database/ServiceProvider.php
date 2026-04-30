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
use App\Base\Database\Console\Commands\TableUnstableCommand;
use App\Base\Database\Console\Commands\WipeCommand;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Console\Migrations\FreshCommand as LaravelFreshCommand;
use Illuminate\Database\Console\Migrations\MigrateCommand as LaravelMigrateCommand;
use Illuminate\Database\Console\Migrations\RefreshCommand as LaravelRefreshCommand;
use Illuminate\Database\Console\Migrations\ResetCommand as LaravelResetCommand;
use Illuminate\Database\Console\Migrations\RollbackCommand as LaravelRollbackCommand;
use Illuminate\Database\Console\Migrations\StatusCommand as LaravelStatusCommand;
use Illuminate\Database\Console\WipeCommand as LaravelWipeCommand;
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
        $this->app->extend(LaravelMigrateCommand::class, function ($_, $app) {
            return new MigrateCommand(
                $app->make(Migrator::class),
                $app->make(Dispatcher::class)
            );
        });

        $this->app->extend(LaravelRollbackCommand::class, function ($_, $app) {
            return new RollbackCommand($app->make(Migrator::class));
        });

        $this->app->extend(LaravelStatusCommand::class, function ($_, $app) {
            return new StatusCommand($app->make(Migrator::class));
        });

        $this->app->extend(LaravelResetCommand::class, function ($_, $app) {
            return new ResetCommand($app->make(Migrator::class));
        });

        $this->app->extend(LaravelRefreshCommand::class, function () {
            return new RefreshCommand;
        });

        $this->app->extend(LaravelFreshCommand::class, function ($_, $app) {
            return new FreshCommand($app->make(Migrator::class));
        });

        $this->app->extend(LaravelWipeCommand::class, function () {
            return new WipeCommand;
        });

        $this->commands([
            TableUnstableCommand::class,
        ]);
    }
}
