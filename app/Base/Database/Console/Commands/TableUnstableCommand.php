<?php

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Contracts\IncubatingSchemaInspector;
use App\Base\Database\Models\TableRegistry;
use App\Base\Database\Services\TableInspector;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Legacy helper that now points developers to source-local incubating schema.
 */
#[AsCommand(name: 'blb:table:unstable')]
class TableUnstableCommand extends Command
{
    protected $signature = 'blb:table:unstable
                            {tables?* : Registered table name(s) (or trailing * prefix match) to locate source migrations}
                            {--list : Show registered tables and their source schema state}';

    protected $description = 'Show where to declare incubating schema in source migrations';

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->showStatus();
        }

        $tables = $this->argument('tables');

        if (empty($tables)) {
            $this->components->error('Provide one or more registered table name(s).');
            $this->line('');
            $this->line('  Source-local workflow:');
            $this->line('    <comment>1.</comment> Add <comment>use App\Base\Database\Concerns\IncubatingSchema;</comment> to the owning migration');
            $this->line('    <comment>2.</comment> Add <comment>use IncubatingSchema;</comment> inside the migration class');
            $this->line('    <comment>3.</comment> Run <comment>php artisan migrate --dev</comment>');
            $this->line('');

            return Command::FAILURE;
        }

        $tableNames = $this->expandTableArguments($tables);

        if ($tableNames === []) {
            $this->components->info('No matching registered tables found.');

            return Command::SUCCESS;
        }

        $rows = TableRegistry::query()
            ->whereIn('table_name', $tableNames)
            ->orderBy('table_name')
            ->get();

        if ($rows->isEmpty()) {
            $this->components->info('No matching registered tables found.');

            return Command::SUCCESS;
        }

        $this->components->warn('Local table stability toggles are retired. Declare incubating schema in the source migration instead.');
        $this->line('');

        $inspector = app(TableInspector::class);
        $schemaStates = app(IncubatingSchemaInspector::class)->schemaStatesForTables($rows->pluck('table_name')->all());

        foreach ($rows as $row) {
            $source = $inspector->migrationSource($row->table_name);
            $relativePath = $source['relative_path'] ?? ($row->migration_file ?? 'Migration file not found');

            $this->components->twoColumnDetail($row->table_name, $relativePath);
            $this->components->twoColumnDetail('Schema state', $schemaStates[$row->table_name] ?? 'unknown');
        }

        $this->line('');
        $this->line('  Add this marker to the owning migration file:');
        $this->line('    <comment>use App\Base\Database\Concerns\IncubatingSchema;</comment>');
        $this->line('    <comment>use IncubatingSchema;</comment>');
        $this->line('  Then run <comment>php artisan migrate --dev</comment>.');

        return Command::SUCCESS;
    }

    /**
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
                    ->pluck('table_name')
                    ->filter(fn (string $tableName): bool => Str::is($arg, $tableName))
                    ->values()
                    ->all();

                $names = array_merge($names, $matched);

                continue;
            }

            $names[] = $arg;
        }

        $names = array_values(array_unique($names));
        sort($names);

        return $names;
    }

    protected function showStatus(): int
    {
        $tables = TableRegistry::query()->orderBy('module_name')->orderBy('table_name')->get();

        if ($tables->isEmpty()) {
            $this->components->info('No tables registered.');

            return Command::SUCCESS;
        }

        $schemaStates = app(IncubatingSchemaInspector::class)->schemaStatesForTables(
            $tables->pluck('table_name')->all(),
        );

        $this->table(
            ['Table', 'Module', 'Schema', 'Migration'],
            $tables->map(fn (TableRegistry $table) => [
                $table->table_name,
                $table->module_name ?? '—',
                $schemaStates[$table->table_name] ?? 'unknown',
                $table->migration_file ?? '—',
            ])->all(),
        );

        return Command::SUCCESS;
    }
}
