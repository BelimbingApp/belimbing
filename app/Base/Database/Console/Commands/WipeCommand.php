<?php

namespace App\Base\Database\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Database\Console\WipeCommand as IlluminateWipeCommand;
use Illuminate\Support\Facades\DB;

class WipeCommand extends IlluminateWipeCommand
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if ($this->isInMemoryTestDatabase()) {
            return (int) parent::handle();
        }

        $this->components->error('db:wipe is blocked because it bypasses BLB incubating-schema safeguards.');
        $this->line('');
        $this->line('  Use <comment>php artisan migrate --dev</comment> for local rebuilds of source-declared incubating schema.');
        $this->line('  <comment>migrate:fresh</comment> does not route around this: it delegates the drop here and is');
        $this->line('  refused the same way. Drop and recreate the database yourself if you mean it.');
        $this->line('');

        return Command::FAILURE;
    }

    private function isInMemoryTestDatabase(): bool
    {
        return self::permitsWipe(DB::connection($this->input->getOption('database')));
    }

    /**
     * The one predicate that decides whether a wipe is allowed.
     *
     * Public and static because FreshCommand has to ask the same question
     * before delegating a drop it cannot see the answer to: a refusal here is
     * lost twice on the way back, once in callSilent's NullOutput and once in
     * Task::render(), which matches a boolean against an int-backed enum and
     * falls through to DONE. See BelimbingApp/belimbing#525.
     */
    public static function permitsWipe(Connection $connection): bool
    {
        return $connection->getDriverName() === 'sqlite'
            && $connection->getDatabaseName() === ':memory:';
    }
}
