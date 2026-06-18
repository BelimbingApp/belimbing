<?php

namespace App\Base\Update;

use App\Base\Update\Console\Commands\DomainRuntimeReloadCommand;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        $this->commands([
            DomainRuntimeReloadCommand::class,
        ]);
    }
}
