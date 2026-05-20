<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Concerns\GuardsPostgresMigrationIdentifiers;
use App\Base\Database\Concerns\InteractsWithModuleMigrations;
use Illuminate\Database\Console\Migrations\RollbackCommand as IlluminateRollbackCommand;

class RollbackCommand extends IlluminateRollbackCommand
{
    use GuardsPostgresMigrationIdentifiers;
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

        return $this->guardPostgresMigrationIdentifiers(
            $this->option('database'),
            fn (): int => parent::handle(),
        );
    }
}
