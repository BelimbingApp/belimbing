<?php

namespace App\Base\Database\Services;

use App\Base\Database\Exceptions\IncubatingSchemaConflictException;
use App\Base\Database\Models\SeederRegistry;
use App\Base\Database\Models\TableRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class IncubatingSchemaRegistry
{
    /**
     * @return list<string>
     */
    public function tablesForMigrationFile(string $migrationFile): array
    {
        if (! Schema::hasTable('base_database_tables')) {
            return [];
        }

        return TableRegistry::query()
            ->where('migration_file', $migrationFile)
            ->pluck('table_name')
            ->all();
    }

    /**
     * @return list<string>
     */
    public function liveTableNames(): array
    {
        return array_map(
            fn (array $table): string => $table['name'],
            Schema::getTables(),
        );
    }

    /**
     * Refuse before the first drop when an incubating migration merely names
     * an existing table owned by different stable source.
     *
     * @param  list<array{file: string, migration_name: string, tables: list<string>}>  $migrations
     */
    public function assertLiveTableOwnership(array $migrations): void
    {
        $live = array_fill_keys($this->liveTableNames(), true);
        $declaredTables = array_values(array_unique(array_merge(...array_map(
            fn (array $migration): array => $migration['tables'],
            $migrations,
        ))));
        $owners = Schema::hasTable('base_database_tables')
            ? TableRegistry::query()->whereIn('table_name', $declaredTables)->pluck('migration_file', 'table_name')->all()
            : [];
        $applied = DB::table('migrations')
            ->whereIn('migration', array_column($migrations, 'migration_name'))
            ->pluck('migration')
            ->flip()
            ->all();
        $conflicts = $this->liveTableOwnershipConflicts($migrations, $live, $owners, $applied);

        if ($conflicts !== []) {
            throw IncubatingSchemaConflictException::forLiveTableOwnership($conflicts);
        }
    }

    /**
     * @param  list<array{file: string, migration_name: string, tables: list<string>}>  $migrations
     * @param  array<string, true>  $live
     * @param  array<string, mixed>  $owners
     * @param  array<string, mixed>  $applied
     * @return list<array{table: string, declared_by: string, owned_by: ?string}>
     */
    private function liveTableOwnershipConflicts(array $migrations, array $live, array $owners, array $applied): array
    {
        $conflicts = [];

        foreach ($migrations as $migration) {
            foreach ($migration['tables'] as $table) {
                if (! isset($live[$table])) {
                    continue;
                }

                $owner = $owners[$table] ?? null;

                if ($owner === $migration['file']
                    || ($owner === null && isset($applied[$migration['migration_name']]))) {
                    continue;
                }

                $conflicts[] = [
                    'table' => $table,
                    'declared_by' => $migration['file'],
                    'owned_by' => is_string($owner) && $owner !== '' ? $owner : null,
                ];
            }
        }

        return $conflicts;
    }

    /**
     * @param  list<string>  $tables
     * @return array<string, string>
     */
    public function migrationFilesForTables(array $tables): array
    {
        if ($tables === [] || ! Schema::hasTable('base_database_tables')) {
            return [];
        }

        return TableRegistry::query()
            ->whereIn('table_name', $tables)
            ->whereNotNull('migration_file')
            ->where('migration_file', '!=', '')
            ->pluck('migration_file', 'table_name')
            ->all();
    }

    /**
     * @param  list<string>  $migrationFiles
     * @return list<string>
     */
    public function liveTablesForMigrationFiles(array $migrationFiles): array
    {
        if ($migrationFiles === [] || ! Schema::hasTable('base_database_tables')) {
            return [];
        }

        $live = array_fill_keys($this->liveTableNames(), true);

        return TableRegistry::query()
            ->whereIn('migration_file', array_values(array_unique($migrationFiles)))
            ->pluck('table_name')
            ->filter(fn (string $table): bool => isset($live[$table]))
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $migrationFiles
     */
    public function resetSeedersForFiles(array $migrationFiles): int
    {
        if (! Schema::hasTable('base_database_seeders')) {
            return 0;
        }

        $migrationFiles = array_values(array_unique($migrationFiles));

        if ($migrationFiles === []) {
            return 0;
        }

        return SeederRegistry::query()
            ->whereIn('migration_file', $migrationFiles)
            ->update([
                'status' => SeederRegistry::STATUS_PENDING,
                'ran_at' => null,
                'error_message' => null,
            ]);
    }
}
