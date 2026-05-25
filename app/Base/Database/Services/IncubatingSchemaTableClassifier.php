<?php

namespace App\Base\Database\Services;

use App\Base\Database\Models\TableRegistry;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class IncubatingSchemaTableClassifier
{
    public function __construct(
        private readonly DeprecatedIncubatingTableList $deprecatedList,
        private readonly IncubatingMigrationFiles $migrationFiles,
    ) {}

    /**
     * @param  list<string>  $tableNames
     * @return array<string, array{state: string, source_declared: bool, deprecated_pattern: string|null}>
     */
    public function detailsForTables(array $tableNames): array
    {
        if ($tableNames === []) {
            return [];
        }

        $rows = TableRegistry::query()
            ->whereIn('table_name', $tableNames)
            ->get(['table_name', 'migration_file']);

        $sourceIncubatingFiles = $this->incubatingFilesForRows($rows);
        $deprecatedPatterns = $this->deprecatedList->matchingPatternsForTables($tableNames);
        $rowsByTable = $rows->keyBy('table_name');
        $details = [];

        foreach ($tableNames as $tableName) {
            $details[$tableName] = $this->detailsForTable(
                $tableName,
                $rowsByTable->get($tableName)?->migration_file,
                $sourceIncubatingFiles,
                $deprecatedPatterns[$tableName] ?? null,
            );
        }

        return $details;
    }

    /**
     * @param  list<string>  $tableNames
     * @return array<string, string>
     */
    public function statesForTables(array $tableNames): array
    {
        if ($tableNames === []) {
            return [];
        }

        $rows = TableRegistry::query()
            ->whereIn('table_name', $tableNames)
            ->get(['table_name', 'migration_file']);

        $incubatingFiles = $this->incubatingFilesForRows($rows);
        $deprecatedTables = $this->deprecatedTables();
        $rowsByTable = $rows->keyBy('table_name');

        return collect($tableNames)
            ->mapWithKeys(function (string $tableName) use ($rowsByTable, $incubatingFiles, $deprecatedTables): array {
                return [
                    $tableName => $this->stateForTable(
                        $tableName,
                        $rowsByTable->get($tableName)?->migration_file,
                        $incubatingFiles,
                        $deprecatedTables,
                    ),
                ];
            })
            ->all();
    }

    /**
     * @return list<string>
     */
    public function deprecatedTables(): array
    {
        $patterns = $this->deprecatedList->patterns();

        if ($patterns === []) {
            return [];
        }

        return TableRegistry::query()
            ->pluck('table_name')
            ->filter(function (string $tableName) use ($patterns): bool {
                foreach ($patterns as $pattern) {
                    if (Str::is($pattern, $tableName)) {
                        return true;
                    }
                }

                return false;
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, TableRegistry>  $rows
     * @return array<string, true>
     */
    private function incubatingFilesForRows(Collection $rows): array
    {
        return $rows
            ->pluck('migration_file')
            ->filter(fn (mixed $file): bool => is_string($file) && $file !== '')
            ->unique()
            ->reduce(function (array $files, string $migrationFile): array {
                if ($this->migrationFiles->fileIsIncubating($migrationFile)) {
                    $files[$migrationFile] = true;
                }

                return $files;
            }, []);
    }

    /**
     * @param  array<string, true>  $incubatingFiles
     * @param  list<string>  $deprecatedTables
     */
    private function stateForTable(
        string $tableName,
        mixed $migrationFile,
        array $incubatingFiles,
        array $deprecatedTables,
    ): string {
        $state = 'stable';

        if (in_array($tableName, TableRegistry::INFRASTRUCTURE_TABLES, true)) {
            $state = 'infrastructure';
        } elseif (! is_string($migrationFile) || $migrationFile === '') {
            $state = 'unknown';
        } elseif (isset($incubatingFiles[$migrationFile]) || in_array($tableName, $deprecatedTables, true)) {
            $state = 'incubating';
        }

        return $state;
    }

    /**
     * @param  array<string, true>  $sourceIncubatingFiles
     * @return array{state: string, source_declared: bool, deprecated_pattern: string|null}
     */
    private function detailsForTable(
        string $tableName,
        mixed $migrationFile,
        array $sourceIncubatingFiles,
        ?string $deprecatedPattern,
    ): array {
        if (in_array($tableName, TableRegistry::INFRASTRUCTURE_TABLES, true)) {
            return [
                'state' => 'infrastructure',
                'source_declared' => false,
                'deprecated_pattern' => null,
            ];
        }

        $sourceDeclared = is_string($migrationFile) && $migrationFile !== '' && isset($sourceIncubatingFiles[$migrationFile]);

        return [
            'state' => $this->detailState($migrationFile, $sourceDeclared, $deprecatedPattern),
            'source_declared' => $sourceDeclared,
            'deprecated_pattern' => $deprecatedPattern,
        ];
    }

    private function detailState(mixed $migrationFile, bool $sourceDeclared, ?string $deprecatedPattern): string
    {
        $state = 'unknown';

        if ($sourceDeclared || $deprecatedPattern !== null) {
            $state = 'incubating';
        } elseif (is_string($migrationFile) && $migrationFile !== '') {
            $state = 'stable';
        }

        return $state;
    }
}
