<?php

namespace App\Base\Database\Services;

use App\Base\Database\Models\TableRegistry;

final class MigrationIncubationManager
{
    /**
     * @param  list<string>  $tableNames
     * @return array{updated: list<string>, skipped: list<string>}
     */
    public function markTablesIncubating(array $tableNames): array
    {
        return $this->updateTables($tableNames, true);
    }

    /**
     * @param  list<string>  $tableNames
     * @return array{updated: list<string>, skipped: list<string>}
     */
    public function unmarkTablesIncubating(array $tableNames): array
    {
        return $this->updateTables($tableNames, false);
    }

    /**
     * @param  list<string>  $tableNames
     * @return array{updated: list<string>, skipped: list<string>}
     */
    private function updateTables(array $tableNames, bool $incubating): array
    {
        $rows = TableRegistry::query()
            ->whereIn('table_name', $tableNames)
            ->get(['table_name', 'migration_file']);

        $byMigration = [];
        $skipped = [];

        foreach ($tableNames as $tableName) {
            $row = $rows->firstWhere('table_name', $tableName);

            if (! $row instanceof TableRegistry || ! is_string($row->migration_file) || $row->migration_file === '') {
                $skipped[] = $tableName.' (no owning migration)';

                continue;
            }

            $path = $this->migrationPathByFileName($row->migration_file);

            if ($path === null) {
                $skipped[] = $tableName.' (migration file missing)';

                continue;
            }

            $byMigration[$path]['file'] = $row->migration_file;
            $byMigration[$path]['tables'][] = $tableName;
        }

        $updated = [];

        foreach ($byMigration as $path => $payload) {
            $tables = array_values(array_unique($payload['tables'] ?? []));
            $result = $incubating
                ? $this->insertTrait($path)
                : $this->removeTrait($path);

            if ($result === 'updated') {
                $updated[] = $payload['file'].' ['.implode(', ', $tables).']';

                continue;
            }

            $reason = $incubating ? 'already source-declared' : 'not source-declared';

            foreach ($tables as $tableName) {
                $skipped[] = $tableName.' ('.$reason.')';
            }
        }

        return [
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    private function migrationPathByFileName(string $migrationFile): ?string
    {
        $patterns = [
            app_path('Base/*/Database/Migrations/*.php'),
            app_path('Modules/*/*/Database/Migrations/*.php'),
            database_path('migrations/*.php'),
            base_path('extensions/*/*/Database/Migrations/*.php'),
        ];

        foreach ($patterns as $pattern) {
            foreach (glob($pattern) ?: [] as $path) {
                if (basename($path) === $migrationFile) {
                    return $path;
                }
            }
        }

        return null;
    }

    private function insertTrait(string $path): string
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new \RuntimeException('Unable to read migration file: '.$path);
        }

        if (preg_match('/^\s*use IncubatingSchema;$/m', $contents) === 1) {
            return 'unchanged';
        }

        $updated = $contents;

        if (! str_contains($updated, "use App\\Base\\Database\\Concerns\\IncubatingSchema;")) {
            $updated = preg_replace(
                '/^(<\?php\s*\R(?:\R)?)/',
                "$1use App\\Base\\Database\\Concerns\\IncubatingSchema;\n",
                $updated,
                1,
            ) ?? $updated;
        }

        $updated = preg_replace(
            '/(return new class extends Migration\s*\R\{(?:\R)?)/',
            "$1    use IncubatingSchema;\n",
            $updated,
            1,
        ) ?? $updated;

        if ($updated === $contents || preg_match('/^\s*use IncubatingSchema;$/m', $updated) !== 1) {
            throw new \RuntimeException('Unable to mark migration incubating: '.$path);
        }

        file_put_contents($path, $updated);

        return 'updated';
    }

    private function removeTrait(string $path): string
    {
        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            throw new \RuntimeException('Unable to read migration file: '.$path);
        }

        if (preg_match('/^\s*use IncubatingSchema;$/m', $contents) !== 1) {
            return 'unchanged';
        }

        $updated = preg_replace('/^\h*use IncubatingSchema;\R/m', '', $contents, 1);
        $updated = is_string($updated) ? $updated : $contents;

        if (substr_count($updated, 'IncubatingSchema') === 1) {
            $updated = str_replace("use App\\Base\\Database\\Concerns\\IncubatingSchema;\n", '', $updated);
            $updated = str_replace("use App\\Base\\Database\\Concerns\\IncubatingSchema;\r\n", '', $updated);
        }

        if ($updated === $contents) {
            return 'unchanged';
        }

        file_put_contents($path, $updated);

        return 'updated';
    }
}
