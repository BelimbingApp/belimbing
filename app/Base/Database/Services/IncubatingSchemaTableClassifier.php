<?php

namespace App\Base\Database\Services;

use App\Base\Database\Models\TableRegistry;
use Illuminate\Support\Collection;

final class IncubatingSchemaTableClassifier
{
    public function __construct(
        private readonly IncubatingMigrationFiles $migrationFiles,
    ) {}

    /**
     * @param  list<string>  $tableNames
     * @return array<string, array{state: string, source_declared: bool}>
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
        $rowsByTable = $rows->keyBy('table_name');
        $details = [];

        foreach ($tableNames as $tableName) {
            $details[$tableName] = $this->detailsForTable(
                $tableName,
                $rowsByTable->get($tableName)?->migration_file,
                $sourceIncubatingFiles,
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

        return collect($this->detailsForTables($tableNames))
            ->mapWithKeys(fn (array $detail, string $tableName): array => [
                $tableName => $detail['state'],
            ])
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
     * @param  array<string, true>  $sourceIncubatingFiles
     * @return array{state: string, source_declared: bool}
     */
    private function detailsForTable(
        string $tableName,
        mixed $migrationFile,
        array $sourceIncubatingFiles,
    ): array {
        if (in_array($tableName, TableRegistry::INFRASTRUCTURE_TABLES, true)) {
            return [
                'state' => 'infrastructure',
                'source_declared' => false,
            ];
        }

        $sourceDeclared = is_string($migrationFile) && $migrationFile !== '' && isset($sourceIncubatingFiles[$migrationFile]);

        return [
            'state' => $this->detailState($migrationFile, $sourceDeclared),
            'source_declared' => $sourceDeclared,
        ];
    }

    private function detailState(mixed $migrationFile, bool $sourceDeclared): string
    {
        $state = 'unknown';

        if ($sourceDeclared) {
            $state = 'incubating';
        } elseif (is_string($migrationFile) && $migrationFile !== '') {
            $state = 'stable';
        }

        return $state;
    }
}
