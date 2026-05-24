<?php

namespace App\Base\Database\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Console\Migrations\FreshCommand as IlluminateFreshCommand;

class FreshCommand extends IlluminateFreshCommand
{
    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Drop all tables and re-run all migrations (Laravel semantics; use only for disposable databases)';

    public function handle()
    {
        if (! $this->isDisposableEnvironment()) {
            $this->components->error('migrate:fresh is blocked outside disposable environments. Use `php artisan migrate --dev` for local incubating-schema rebuilds.');

            return Command::FAILURE;
        }

        return parent::handle();
    }

    private function isDisposableEnvironment(): bool
    {
        if (app()->environment(['local', 'testing'])) {
            return true;
        }

        $database = $this->input->getOption('database');
        $connection = $this->migrator->resolveConnection($database);

        return $connection->getDriverName() === 'sqlite'
            && $connection->getDatabaseName() === ':memory:';
    }
}
