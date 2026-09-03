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

        if ($this->wipeWillBeRefused()) {
            $this->components->warn('The drop below will be refused: db:wipe permits only in-memory SQLite, so this is a migrate in place, not a fresh database.');
            $this->line('');
            $this->line('  The <comment>Dropping all tables ... DONE</comment> line that follows is not a drop.');
            $this->line('  Task::render() matches a boolean against an int-backed enum, so it cannot');
            $this->line('  print anything else. See BelimbingApp/belimbing#525.');
            $this->line('');
            $this->line('  This is expected and depended upon — see tests/AGENTS.md. If the schema');
            $this->line('  here is stale, drop and recreate the database; migrate:fresh will not.');
            $this->line('');
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

    /**
     * True when the drop this command is about to delegate cannot happen.
     *
     * Deliberately does NOT block. Both PostgreSQL CI lanes migrate the test
     * database in an earlier step and then rely on migrate:fresh degrading to
     * a plain migrate, and tests/AGENTS.md states that degrade as a rule
     * authors are expected to work with. Refusing here fails 125 tests on one
     * lane and 7 on the other — and fails silently, because RefreshDatabase
     * discards the exit code and the seeder never runs, so the visible error
     * is a missing platform-operator tenant. That is the same defect this
     * command is being made honest about, one frame further out.
     *
     * So: say what is happening, and let it happen.
     */
    private function wipeWillBeRefused(): bool
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
}
