<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Concerns\InteractsWithModuleMigrations;
use Illuminate\Database\Console\Migrations\RollbackCommand as IlluminateRollbackCommand;

class RollbackCommand extends IlluminateRollbackCommand
{
    use InteractsWithModuleMigrations;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rollback the last database migration';

    /**
     * Execute the console command.
     *
     * Loads all module migrations before rolling back so the migrator
     * can resolve migration files from module discovery paths.
     */
    public function handle(): int
    {
        $this->loadAllModuleMigrations();

        return parent::handle();
    }
}
