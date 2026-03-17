<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Models\TableRegistry;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Mark tables as unstable so migrate:fresh will drop and rebuild them.
 */
#[AsCommand(name: 'blb:table:unstable')]
class TableUnstableCommand extends Command
{
    protected $signature = 'blb:table:unstable
                            {tables?* : Table name(s) to mark unstable}
                            {--list : Show current stable/unstable status of all tables}';

    protected $description = 'Mark database tables as unstable so migrate:fresh will drop them';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->showStatus();
        }

        $tables = $this->argument('tables');

        if (empty($tables)) {
            $this->components->error('Provide one or more table name(s).');
            $this->line('');
            $this->line('  <comment>php artisan blb:table:unstable users</comment>              Mark one table');
            $this->line('  <comment>php artisan blb:table:unstable users companies</comment>    Mark multiple tables');
            $this->line('  <comment>php artisan blb:table:unstable --list</comment>             Show table stability status');
            $this->line('');

            return Command::FAILURE;
        }

        $query = TableRegistry::query()->stable()->whereIn('table_name', $tables);

        $rows = $query->get();

        if ($rows->isEmpty()) {
            $this->components->info('No matching stable tables found.');

            return Command::SUCCESS;
        }

        $marked = 0;

        foreach ($rows as $row) {
            $row->markUnstable();
            $this->components->twoColumnDetail($row->table_name, '<fg=yellow>unstable</>');
            $marked++;
        }

        $this->line('');
        $this->components->info("Marked {$marked} table(s) as unstable. Run `php artisan migrate:fresh --seed --dev` to rebuild them.");

        return Command::SUCCESS;
    }

    /**
     * Show stability status for all registered tables.
     */
    protected function showStatus(): int
    {
        $tables = TableRegistry::query()->orderBy('module_name')->orderBy('table_name')->get();

        if ($tables->isEmpty()) {
            $this->components->info('No tables registered.');

            return Command::SUCCESS;
        }

        $this->table(
            ['Table', 'Module', 'Stable'],
            $tables->map(fn (TableRegistry $t) => [
                $t->table_name,
                $t->module_name ?? '—',
                $t->is_stable ? '<fg=green>✓</>' : '<fg=yellow>✗</>',
            ])->all(),
        );

        return Command::SUCCESS;
    }
}
