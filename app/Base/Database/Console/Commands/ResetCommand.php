<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Concerns\GuardsGlobalReset;
use App\Base\Database\Concerns\GuardsPostgresMigrationIdentifiers;
use App\Base\Database\Concerns\InteractsWithModuleMigrations;
use Illuminate\Database\Console\Migrations\ResetCommand as IlluminateResetCommand;

class ResetCommand extends IlluminateResetCommand
{
    use GuardsGlobalReset;
    use GuardsPostgresMigrationIdentifiers;
    use InteractsWithModuleMigrations;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Rollback all database migrations';

    /**
     * Execute the console command.
     *
     * Loads all module migrations before resetting. Blocks unscoped reset
     * outside in-memory SQLite test databases.
     */
    public function handle(): int
    {
        if ($result = $this->guardGlobalReset()) {
            return $result;
        }

        $this->loadAllModuleMigrations();

        return $this->guardPostgresMigrationIdentifiers(
            $this->option('database'),
            fn (): int => (int) parent::handle(),
        );
    }
}
