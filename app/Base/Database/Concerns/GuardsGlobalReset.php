<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Concerns;

use App\Base\Database\Console\Concerns\PrintsTableUnstableUsage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Guards against global database reset/refresh.
 *
 * Blocks `migrate:refresh` and `migrate:reset` unless `--force-wipe` is
 * passed or the database is an in-memory SQLite test database.
 *
 * `migrate:fresh --seed --dev` is the blessed full-reset workflow because it
 * respects table stability. `refresh`/`reset` operate at the migration level
 * where stability cannot be enforced.
 */
trait GuardsGlobalReset
{
    use PrintsTableUnstableUsage;

    /**
     * Block reset/refresh unless explicitly overridden.
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

        if ($this->option('force-wipe')) {
            return null;
        }

        $this->components->error(
            $this->name.' is blocked — it bypasses table stability and would wipe the entire database.'
        );
        $this->line('');
        $this->line('  Use one of these instead:');
        $this->line('');
        $this->line('    <comment>php artisan migrate:fresh --seed --dev</comment>        Full rebuild (respects table stability)');
        $this->line('    <comment>php artisan '.$this->name.' --force-wipe</comment>          Intentional full reset (dangerous)');
        $this->line('');
        $this->printTableUnstableUsage('  To mark specific tables unstable before migrate:fresh:');

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
