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

        $this->components->error('db:wipe is blocked because it bypasses BLB incubating-schema safeguards.');
        $this->line('');
        $this->line('  Use <comment>php artisan migrate --dev</comment> for local rebuilds of source-declared incubating schema.');
        $this->line('  Use <comment>php artisan migrate:fresh</comment> only when you intentionally want a full Laravel wipe.');
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
