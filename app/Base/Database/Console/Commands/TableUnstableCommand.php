<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Console\Concerns\PrintsTableUnstableUsage;
use App\Base\Database\Models\TableRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Mark tables as unstable so migrate:fresh will drop and rebuild them.
 */
#[AsCommand(name: 'blb:table:unstable')]
class TableUnstableCommand extends Command
{
    use PrintsTableUnstableUsage;

    protected $signature = 'blb:table:unstable
                            {tables?* : Table name(s) or * wildcard pattern(s) to mark unstable}
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
            $this->printTableUnstableUsage('  Examples:');
            $this->line('  <comment>php artisan blb:table:unstable --list</comment>             Show table stability status');
            $this->line('');

            return Command::FAILURE;
        }

        $tableNames = $this->expandTableArguments($tables);

        if ($tableNames === []) {
            $this->components->info('No matching stable tables found.');

            return Command::SUCCESS;
        }

        $query = TableRegistry::query()->stable()->whereIn('table_name', $tableNames);

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
     * Expand table arguments to concrete table names.
     *
     * Supports:
     * - Exact names: users, companies
     * - Wildcards: ai_*, people_*_entitlement_*
     *
     * @param  array<int, string>  $args
     * @return array<int, string>
     */
    private function expandTableArguments(array $args): array
    {
        $names = [];

        foreach ($args as $arg) {
            $arg = trim((string) $arg);
            if ($arg === '') {
                continue;
            }

            if (str_contains($arg, '*')) {
                if ($arg === '*') {
                    continue;
                }

                $matched = TableRegistry::query()
                    ->stable()
                    ->pluck('table_name')
                    ->filter(fn (string $tableName): bool => Str::is($arg, $tableName))
                    ->values()
                    ->all();

                $names = array_merge($names, $matched);
            } else {
                $names[] = $arg;
            }
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
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
