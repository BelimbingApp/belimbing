<?php

namespace App\Base\Software;

use App\Base\Software\Console\Commands\DomainRuntimeReloadCommand;
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
