<?php

namespace App\Base\Database\Services;

use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Classifies source-declared incubating migrations before a non-disposable
 * migrate. Applied incubating sources are schema debt; pending ones are guarded.
 */
final class IncubatingSchemaProductionPolicy
{
    private const SOURCE_TABLE = 'base_database_migration_sources';

    public function __construct(
        private readonly IncubatingMigrationFiles $migrationFiles,
        private readonly IncubatingSchemaApprovalRepository $approvals,
    ) {}

    /**
     * @param  list<string>  $migrationPaths
     * @return array{applied: list<array<string, mixed>>, pending: list<array<string, mixed>>, approved: list<array<string, mixed>>, drifted: list<array<string, mixed>>}
     */
    public function evaluate(array $migrationPaths, ?string $connectionName = null): array
    {
        $ran = $this->ranMigrations($connectionName);
        $records = $this->sourceRecords($connectionName);
        $report = [
            'applied' => [],
            'pending' => [],
            'approved' => [],
            'drifted' => [],
        ];
        $seen = [];

        foreach ($this->incubatingFindings($migrationPaths) as $finding) {
            $seen[$finding['migration_name']] = true;

            if (! isset($ran[$finding['migration_name']])) {
                $approval = $this->approvals->approvalFor($finding, $connectionName);

                if ($approval !== null) {
                    $finding['approval'] = $approval;
                    $report['approved'][] = $finding;

                    continue;
                }

                $report['pending'][] = $finding;

                continue;
            }

            $record = $records[$finding['migration_name']] ?? null;

            if ($record !== null && (string) $record->source_sha256 !== $finding['sha256']) {
                $finding['recorded_sha256'] = (string) $record->source_sha256;
                $finding['recorded_path'] = (string) $record->relative_path;
                $report['drifted'][] = $finding;

                continue;
            }

            $finding['baseline'] = $record === null ? 'missing' : 'matched';
            $report['applied'][] = $finding;
        }

        foreach ($records as $migrationName => $record) {
            if (isset($seen[$migrationName])
                || ! isset($ran[$migrationName])
                || (string) ($record->source_state ?? '') !== 'incubating') {
                continue;
            }

            $finding = $this->recordedSourceFinding((string) $migrationName, $record);

            if ($finding === null || $finding['sha256'] !== (string) $record->source_sha256) {
                $report['drifted'][] = [
                    'path' => $finding['path'] ?? null,
                    'relative_path' => (string) $record->relative_path,
                    'file' => (string) $record->migration_file,
                    'migration_name' => (string) $migrationName,
                    'tables' => [],
                    'sha256' => $finding['sha256'] ?? 'missing',
                    'recorded_sha256' => (string) $record->source_sha256,
                    'recorded_path' => (string) $record->relative_path,
                ];
            }
        }

        return $report;
    }

    /**
     * Record source fingerprints for applied incubating migrations after a
     * successful migrate. First production run after this feature baselines
     * existing incubating schema; later source edits are detected before migrate.
     *
     * @param  list<string>  $migrationPaths
     */
    public function recordAppliedIncubatingSources(array $migrationPaths, ?string $connectionName = null): void
    {
        if (! $this->schema($connectionName)->hasTable(self::SOURCE_TABLE)) {
            return;
        }

        $connection = DB::connection($connectionName);
        $ran = $this->ranMigrations($connectionName);
        $now = now()->utc();

        foreach ($this->incubatingFindings($migrationPaths) as $finding) {
            if (! isset($ran[$finding['migration_name']])) {
                continue;
            }

            $existing = $connection->table(self::SOURCE_TABLE)
                ->where('migration_name', $finding['migration_name'])
                ->first();

            $payload = [
                'migration_file' => $finding['file'],
                'relative_path' => $finding['relative_path'],
                'source_sha256' => $finding['sha256'],
                'source_state' => 'incubating',
                'last_observed_at' => $now,
                'updated_at' => $now,
            ];

            if ($existing === null) {
                $connection->table(self::SOURCE_TABLE)->insert(array_merge($payload, [
                    'migration_name' => $finding['migration_name'],
                    'first_observed_at' => $now,
                    'created_at' => $now,
                ]));

                continue;
            }

            $connection->table(self::SOURCE_TABLE)
                ->where('migration_name', $finding['migration_name'])
                ->update($payload);
        }
    }

    /**
     * Return only findings whose migration is now recorded on the selected
     * connection. Used to consume approvals based on the durable ledger rather
     * than the migrate command's final exit code.
     *
     * @param  list<array{migration_name: string, relative_path: string, sha256: string}>  $findings
     * @return list<array{migration_name: string, relative_path: string, sha256: string}>
     */
    public function appliedFindings(array $findings, ?string $connectionName = null): array
    {
        $ran = $this->ranMigrations($connectionName);

        return array_values(array_filter(
            $findings,
            fn (array $finding): bool => isset($ran[$finding['migration_name']]),
        ));
    }

    /**
     * @param  list<string>  $migrationPaths
     * @return list<array{path: string, relative_path: string, file: string, migration_name: string, tables: list<string>, sha256: string}>
     */
    public function incubatingFindings(array $migrationPaths): array
    {
        $findings = [];

        foreach ($this->migrationFiles->paths($migrationPaths) as $path) {
            $contents = file_get_contents($path);

            if ($contents === false || ! $this->migrationFiles->contentsAreIncubating($contents)) {
                continue;
            }

            $hash = hash_file('sha256', $path);

            $findings[] = [
                'path' => $path,
                'relative_path' => $this->relativeBasePath($path),
                'file' => basename($path),
                'migration_name' => pathinfo($path, PATHINFO_FILENAME),
                'tables' => $this->parsedCreatedTables($contents),
                'sha256' => $hash === false ? '' : $hash,
            ];
        }

        return $findings;
    }

    private function relativeBasePath(string $path): string
    {
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $normalized = str_replace('\\', '/', $path);

        return str_starts_with($normalized, $base)
            ? substr($normalized, strlen($base))
            : $normalized;
    }

    /**
     * @return list<string>
     */
    private function parsedCreatedTables(string $contents): array
    {
        if (! preg_match_all('/(?:Schema::create|->create\w*Table)\(\s*[\'"]([\w]+)[\'"]/', $contents, $matches)) {
            return [];
        }

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * @return array{path: string, relative_path: string, file: string, migration_name: string, sha256: string}|null
     */
    private function recordedSourceFinding(string $migrationName, object $record): ?array
    {
        $path = $this->recordedSourcePath($record);

        if ($path === null) {
            return null;
        }

        $hash = hash_file('sha256', $path);

        return [
            'path' => $path,
            'relative_path' => $this->relativeBasePath($path),
            'file' => basename($path),
            'migration_name' => $migrationName,
            'sha256' => $hash === false ? '' : $hash,
        ];
    }

    private function recordedSourcePath(object $record): ?string
    {
        $relativePath = (string) ($record->relative_path ?? '');

        if ($relativePath !== '') {
            $path = base_path(str_replace('/', DIRECTORY_SEPARATOR, $relativePath));

            if (is_file($path)) {
                return $path;
            }
        }

        $file = (string) ($record->migration_file ?? '');

        return $file !== '' ? $this->migrationFiles->pathByFileName($file) : null;
    }

    /**
     * @return array<string, true>
     */
    private function ranMigrations(?string $connectionName): array
    {
        if (! $this->schema($connectionName)->hasTable('migrations')) {
            return [];
        }

        return DB::connection($connectionName)->table('migrations')
            ->pluck('migration')
            ->mapWithKeys(fn (string $migration): array => [$migration => true])
            ->all();
    }

    /**
     * @return array<string, object>
     */
    private function sourceRecords(?string $connectionName): array
    {
        if (! $this->schema($connectionName)->hasTable(self::SOURCE_TABLE)) {
            return [];
        }

        return DB::connection($connectionName)->table(self::SOURCE_TABLE)
            ->get()
            ->keyBy('migration_name')
            ->all();
    }

    private function schema(?string $connectionName): Builder
    {
        return Schema::connection($connectionName);
    }
}
