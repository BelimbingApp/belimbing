<?php

namespace App\Base\System;

use App\Base\System\Console\Commands\KeyGenerateCommand;
use App\Base\System\Console\Commands\KeyRotateCommand;
use App\Base\System\Console\Commands\TestCommand;
use Illuminate\Foundation\Console\KeyGenerateCommand as LaravelKeyGenerateCommand;
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

        $this->commands([
            KeyRotateCommand::class,
        ]);
    }
}
