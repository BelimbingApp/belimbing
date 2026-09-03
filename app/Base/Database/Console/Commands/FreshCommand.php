<?php

namespace App\Base\Database\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Console\Migrations\FreshCommand as IlluminateFreshCommand;
use Throwable;

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

        if ($this->wipeWouldBeSilentlyRefused()) {
            $this->components->error('migrate:fresh would drop nothing here: db:wipe refuses this connection, and the refusal is not visible from inside migrate:fresh.');
            $this->line('');
            $this->line('  Without this guard the run reports <comment>Dropping all tables ... DONE</comment>, drops');
            $this->line('  nothing, and then migrates against the schema that was already there --');
            $this->line('  so a second test run silently exercises the first run\'s schema.');
            $this->line('');
            $this->line('  Use <comment>php artisan migrate --dev</comment> for incubating-schema rebuilds, or drop');
            $this->line('  and recreate the database yourself if you mean a full wipe.');
            $this->line('');

            return Command::FAILURE;
        }

        return parent::handle();
    }

    /**
     * True when a drop will be attempted, and refused without saying so.
     *
     * Deliberately narrow. On a database with no migration repository there is
     * nothing to drop, Laravel never calls db:wipe, and migrate:fresh is just
     * migrate -- which is the case CI is in, since every job gets a new
     * PostgreSQL service container. Blocking that would break every
     * RefreshDatabase test for no benefit. What this catches is the reused
     * database, where the drop matters and its refusal is invisible.
     */
    private function wipeWouldBeSilentlyRefused(): bool
    {
        $database = $this->input->getOption('database');

        if (WipeCommand::permitsWipe($this->migrator->resolveConnection($database))) {
            return false;
        }

        return $this->migrator->usingConnection($database, function (): bool {
            try {
                return $this->migrator->repositoryExists();
            } catch (Throwable) {
                return false;
            }
        });
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
