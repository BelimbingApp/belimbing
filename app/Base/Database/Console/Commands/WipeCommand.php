<?php
namespace App\Base\Database\Console\Commands;

use Illuminate\Console\Command;
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

        $this->components->error('db:wipe is blocked because it bypasses BLB table stability.');
        $this->line('');
        $this->line('  Use <comment>php artisan migrate:fresh --seed --dev</comment> after marking affected tables unstable.');
        $this->line('  Example: <comment>php artisan blb:table:unstable ai_pricing_snapshots ai_pricing_overrides</comment>');
        $this->line('');

        return Command::FAILURE;
    }

    private function isInMemoryTestDatabase(): bool
    {
        $connection = DB::connection($this->input->getOption('database'));

        return $connection->getDriverName() === 'sqlite'
            && $connection->getDatabaseName() === ':memory:';
    }
}
