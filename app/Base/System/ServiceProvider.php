<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\System;

use App\Base\System\Console\Commands\KeyGenerateCommand;
use App\Base\System\Console\Commands\KeyRotateCommand;
use Illuminate\Foundation\Console\KeyGenerateCommand as LaravelKeyGenerateCommand;
use Illuminate\Support\ServiceProvider as BaseServiceProvider;

class ServiceProvider extends BaseServiceProvider
{
    public function register(): void
    {
        // Block the raw key:generate command — it would strand all backup DEKs.
        // Operators must use blb:key:rotate which re-wraps DEKs atomically.
        $this->app->extend(LaravelKeyGenerateCommand::class, fn () => new KeyGenerateCommand);

        $this->commands([
            KeyRotateCommand::class,
        ]);
    }
}
