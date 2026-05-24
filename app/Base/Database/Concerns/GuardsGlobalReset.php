<?php

namespace App\Base\Database\Concerns;

use App\Base\Database\Console\Concerns\PrintsTableUnstableUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Guards against global database reset/refresh.
 *
 * Blocks `migrate:refresh` and `migrate:reset` unless the database is an
 * in-memory SQLite test database.
 *
 * `migrate --dev` is the blessed local rebuild workflow because it can honor
 * source-declared incubating schema before Laravel's native migrator runs.
 * `refresh`/`reset` operate at the migration level and bypass that preflight.
 */
trait GuardsGlobalReset
{
    use PrintsTableUnstableUsage;

    /**
     * Block reset/refresh.
     *
     * Returns null when the operation is allowed, or Command::FAILURE
     * when it should be blocked.
     *
     * @return int|null null = proceed, Command::FAILURE = abort
     */
    protected function guardGlobalReset(): ?int
    {
        if ($this->isInMemoryTestDatabase()) {
            return null;
        }

        $this->components->error(
            $this->name.' is blocked — it bypasses the incubating-schema rebuild flow and would wipe the entire database.'
        );
        $this->line('');
        $this->line('  Use one of these instead:');
        $this->line('');
        $this->line('    <comment>php artisan migrate --dev</comment>                Local rebuild for source-declared incubating schema');
        $this->line('    <comment>php artisan migrate:fresh</comment>               Full Laravel wipe only when the database is disposable');

        return Command::FAILURE;
    }

    /**
     * Check if running against an in-memory SQLite test database.
     */
    protected function isInMemoryTestDatabase(): bool
    {
        $connection = DB::connection($this->input->getOption('database'));

        return $connection->getDriverName() === 'sqlite'
            && $connection->getDatabaseName() === ':memory:';
    }
}
