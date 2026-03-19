<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Console\Commands;

use App\Base\Database\Console\Concerns\PrintsTableUnstableUsage;
use App\Base\Database\Models\TableRegistry;
use Illuminate\Console\Command;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Console\Migrations\FreshCommand as IlluminateFreshCommand;
use Illuminate\Database\Events\DatabaseRefreshed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Console\Input\InputOption;

class FreshCommand extends IlluminateFreshCommand
{
    use PrintsTableUnstableUsage;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Drop all tables and re-run all migrations (with module, dev, and table stability support)';

    /**
     * Execute the console command.
     *
     * Re-implements parent to support table stability. Tables marked as stable
     * are preserved during the wipe unless --force-wipe is passed. Passes
     * --seed, --seeder, and --dev through to `migrate` instead of handling
     * seeding separately.
     */
    public function handle()
    {
        if ($this->isProhibited() ||
            ! $this->confirmToProceed()) {
            return Command::FAILURE;
        }

        $database = $this->input->getOption('database');
        $forceWipe = $this->option('force-wipe');

        $this->migrator->usingConnection($database, function () use ($database, $forceWipe) {
            if (! $this->migrator->repositoryExists()) {
                return;
            }

            $this->newLine();

            if ($forceWipe) {
                $this->components->task('Dropping all tables (force wipe)', fn () => $this->callSilent('db:wipe', array_filter([
                    '--database' => $database,
                    '--drop-views' => $this->option('drop-views'),
                    '--drop-types' => $this->option('drop-types'),
                    '--force' => true,
                ])) == 0);
            } else {
                $this->dropTablesSelectively($database);
            }
        });

        $this->newLine();

        // Pass --seed and --dev through to migrate so MigrateCommand handles
        // both production seeders and dev seeders in a single flow.
        $this->call('migrate', array_filter([
            '--database' => $database,
            '--path' => $this->input->getOption('path'),
            '--realpath' => $this->input->getOption('realpath'),
            '--schema-path' => $this->input->getOption('schema-path'),
            '--force' => true,
            '--step' => $this->option('step'),
            '--seed' => $this->needsSeeding(),
            '--seeder' => $this->option('seeder'),
            '--dev' => $this->option('dev'),
        ]));

        if ($this->laravel->bound(Dispatcher::class)) {
            $this->laravel[Dispatcher::class]->dispatch(
                new DatabaseRefreshed($database, $this->needsSeeding())
            );
        }

        return 0;
    }

    /**
     * Drop tables selectively, preserving stable and infrastructure tables.
     *
     * If no stable tables exist, falls back to a full db:wipe for efficiency.
     * Otherwise, drops only non-preserved tables and cleans up their migration
     * records so they will be re-run.
     *
     * @param  string|null  $database  Database connection name
     */
    protected function dropTablesSelectively(?string $database): void
    {
        $preservedTables = $this->getPreservedTableNames();

        if ($preservedTables === TableRegistry::INFRASTRUCTURE_TABLES) {
            // No user-stable tables — full wipe is fine
            $this->components->task('Dropping all tables', fn () => $this->callSilent('db:wipe', array_filter([
                '--database' => $database,
                '--drop-views' => $this->option('drop-views'),
                '--drop-types' => $this->option('drop-types'),
                '--force' => true,
            ])) == 0);

            return;
        }

        // Selective drop: preserve stable + infrastructure tables
        $allTables = $this->getAllTableNames();
        $tablesToDrop = array_diff($allTables, $preservedTables);

        if (empty($tablesToDrop)) {
            $this->components->info('All tables are stable — nothing to drop.');
            $this->printTableUnstableUsage('  To mark tables unstable so they can be dropped:');
            $this->line('    <comment>php artisan migrate:fresh --seed --dev --force-wipe</comment>  Ignore stability (nuclear)');
            $this->line('');

            return;
        }

        $stableCount = count($preservedTables) - count(TableRegistry::INFRASTRUCTURE_TABLES);
        $this->components->info("Preserving {$stableCount} stable table(s).");

        $this->components->task(
            'Dropping '.count($tablesToDrop).' non-stable table(s)',
            function () use ($tablesToDrop) {
                Schema::disableForeignKeyConstraints();

                try {
                    foreach ($tablesToDrop as $table) {
                        Schema::dropIfExists($table);
                    }
                } finally {
                    Schema::enableForeignKeyConstraints();
                }

                // Clean up migration records for dropped tables so they re-run.
                // Preserved tables keep their migration records intact.
                $this->cleanupMigrationRecords($tablesToDrop);
            }
        );

        if ($this->option('drop-views')) {
            $this->components->task('Dropping all views', fn () => $this->dropAllViews($database));
        }
    }

    /**
     * Get table names that should be preserved (stable + infrastructure).
     *
     * @return array<string>
     */
    protected function getPreservedTableNames(): array
    {
        if (! Schema::hasTable('base_database_tables')) {
            return TableRegistry::INFRASTRUCTURE_TABLES;
        }

        return TableRegistry::getPreservedTableNames();
    }

    /**
     * Get all table names from the current database connection.
     *
     * @return array<string>
     */
    protected function getAllTableNames(): array
    {
        return array_map(
            fn (array $table) => $table['name'],
            Schema::getTables()
        );
    }

    /**
     * Remove migration records for tables that were dropped.
     *
     * Matches migration file names against the dropped table names. A migration
     * record is removed if its name contains any of the dropped table names
     * (convention: migration names include the table name they create).
     *
     * @param  array<string>  $droppedTables  Table names that were dropped
     */
    protected function cleanupMigrationRecords(array $droppedTables): void
    {
        $query = DB::table('migrations');

        $query->where(function ($q) use ($droppedTables) {
            foreach ($droppedTables as $table) {
                $q->orWhere('migration', 'like', "%{$table}%");
            }
        });

        $query->delete();
    }

    /**
     * Drop all views for the given database connection.
     *
     * @param  string|null  $database  Database connection name
     */
    protected function dropAllViews(?string $database): void
    {
        $this->callSilent('db:wipe', array_filter([
            '--database' => $database,
            '--drop-views' => true,
            '--force' => true,
        ]));
    }

    /**
     * Determine if the developer has requested database seeding.
     *
     * Extends parent to also consider --dev as needing seeding.
     *
     * @return bool
     */
    protected function needsSeeding()
    {
        return $this->option('seed') || $this->option('seeder') || $this->option('dev');
    }

    /**
     * Get the console command options.
     *
     * Extends parent by adding --dev and --force-wipe options.
     *
     * @return array
     */
    protected function getOptions()
    {
        return array_merge(parent::getOptions(), [
            ['dev', null, InputOption::VALUE_NONE, 'Run dev seeders after production seeders (APP_ENV=local only). Implies --seed.'],
            ['force-wipe', null, InputOption::VALUE_NONE, 'Ignore table stability — drop ALL tables (original migrate:fresh behavior).'],
        ]);
    }
}
