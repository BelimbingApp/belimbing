<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Contracts\DataShareMirrorEngine;
use App\Base\Database\Contracts\DataShareMirrorProcessRunner;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorExecutionResult;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReview;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReviewItem;
use App\Base\Database\Enums\DataShareMirrorAction;
use App\Base\Database\Exceptions\DataShareMirrorException;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use Throwable;

class DataShareMirrorTableImageEngine implements DataShareMirrorEngine
{
    private const DEFAULT_TIMEOUT = 3600;

    public function __construct(
        private readonly DataShareMirrorConnectionManager $connections,
        private readonly DataShareMirrorProcessRunner $processes,
        private readonly Filesystem $files,
        private readonly DataShareMirrorTemporaryFiles $temporaryFiles,
    ) {}

    public function mode(): string
    {
        return 'native';
    }

    public function execute(DataShareMirrorReview $review): DataShareMirrorExecutionResult
    {
        if ($review->hasBlockers) {
            throw DataShareMirrorException::blocked();
        }

        $source = $this->connections->source($review->direction);
        $target = $this->connections->target($review->direction);
        $sourceTables = array_values(array_map(
            fn (DataShareMirrorReviewItem $item): string => $item->table,
            array_filter($review->items, fn (DataShareMirrorReviewItem $item): bool => $item->intendedAction !== DataShareMirrorAction::Delete),
        ));
        $targetTables = array_values(array_map(
            fn (DataShareMirrorReviewItem $item): string => $item->table,
            array_filter($review->items, fn (DataShareMirrorReviewItem $item): bool => $item->intendedAction !== DataShareMirrorAction::Create),
        ));
        $pgDump = $this->processes->find('pg_dump');
        $psql = $this->processes->find('psql');
        if ($pgDump === null || $psql === null) {
            throw DataShareMirrorException::unavailable(__('Install compatible pg_dump and psql client tools on this host.'));
        }

        $dumpPath = null;
        $programPath = null;

        try {
            $dumpPath = $this->temporaryFiles->create('blb-mirror-', '.dump.sql');
            $programPath = $this->temporaryFiles->create('blb-mirror-', '.program.sql');

            if ($sourceTables !== []) {
                $command = [
                    $pgDump,
                    '--format=plain',
                    '--no-owner',
                    '--no-privileges',
                    '--strict-names',
                    '--schema=public',
                    '--file='.$dumpPath,
                ];

                foreach ($sourceTables as $table) {
                    $command[] = '--table=public.'.$this->quotedIdentifier($table);
                }

                $result = $this->processes->run(
                    $command,
                    $this->processEnvironment($source->configuration),
                    $this->timeout(),
                );
                if (! $result->successful()) {
                    throw DataShareMirrorException::preMutationProcessFailed('pg_dump', $result->exitCode);
                }
            } else {
                $this->files->put($dumpPath, '');
            }

            @chmod($dumpPath, 0600);
            $this->buildProgram($programPath, $dumpPath, $target->connection, $source->connection, $targetTables, $sourceTables, $review);

            $result = $this->processes->run([
                $psql,
                '--no-psqlrc',
                '--single-transaction',
                '--set=ON_ERROR_STOP=1',
                '--file='.$programPath,
            ], $this->processEnvironment($target->configuration), $this->timeout());

            if (! $result->successful()) {
                throw DataShareMirrorException::processFailed('psql', $result->exitCode);
            }

            $counts = ['create' => 0, 'replace' => 0, 'delete' => 0];
            $items = [];
            foreach ($review->items as $item) {
                $counts[$item->intendedAction->value]++;
                $items[] = ['table' => $item->table, 'action' => $item->intendedAction->value];
            }

            return new DataShareMirrorExecutionResult($review->direction, $counts, $items);
        } finally {
            foreach ([$dumpPath, $programPath] as $path) {
                if (is_string($path) && is_file($path)) {
                    try {
                        $this->files->delete($path);
                    } catch (Throwable) {
                        @unlink($path);
                    }
                }
            }
        }
    }

    /**
     * @param  list<string>  $targetTables
     * @param  list<string>  $sourceTables
     */
    private function buildProgram(
        string $programPath,
        string $dumpPath,
        Connection $target,
        Connection $source,
        array $targetTables,
        array $sourceTables,
        DataShareMirrorReview $review,
    ): void {
        $handle = fopen($programPath, 'wb');
        if ($handle === false) {
            throw DataShareMirrorException::safeFailure(__('The native mirror program could not be prepared.'));
        }

        try {
            fwrite($handle, "\\set ON_ERROR_STOP on\n");
            fwrite($handle, 'SET LOCAL lock_timeout = '.max(100, (int) config('data_share.mirror.lock_timeout_ms', 30000)).";\n");

            if ($targetTables !== []) {
                $dropTables = array_map(
                    fn (string $table): string => 'public.'.$this->quotedIdentifier($table),
                    $targetTables,
                );
                fwrite($handle, 'DROP TABLE IF EXISTS '.implode(', ', $dropTables).";\n");
            }

            $dump = fopen($dumpPath, 'rb');
            if ($dump === false) {
                throw DataShareMirrorException::preMutationProcessFailed('pg_dump');
            }

            try {
                stream_copy_to_stream($dump, $handle);
            } finally {
                fclose($dump);
            }

            fwrite($handle, "\n".$this->registrySql($source, $target, $sourceTables, array_values(array_diff($targetTables, $sourceTables))));
            fwrite($handle, $this->postconditionSql($target, $review));
        } finally {
            fclose($handle);
        }

        @chmod($programPath, 0600);
    }

    /**
     * @param  list<string>  $upsertTables
     * @param  list<string>  $deleteTables
     */
    private function registrySql(Connection $source, Connection $target, array $upsertTables, array $deleteTables): string
    {
        $sql = '';

        if ($upsertTables !== []) {
            $rows = $source->table('base_database_tables')
                ->whereIn('table_name', $upsertTables)
                ->get(['table_name', 'module_name', 'module_path', 'migration_file', 'stabilized_at', 'stabilized_by'])
                ->keyBy('table_name');

            foreach ($upsertTables as $table) {
                $row = $rows[$table] ?? null;
                if ($row === null) {
                    throw DataShareMirrorException::invalidSelection(__('Source ownership metadata is missing for :table.', ['table' => $table]));
                }

                $values = [
                    $this->sqlValue($target, $table),
                    $this->sqlValue($target, $row->module_name),
                    $this->sqlValue($target, $row->module_path),
                    $this->sqlValue($target, $row->migration_file),
                    $this->sqlValue($target, $row->stabilized_at),
                    $this->sqlValue($target, $row->stabilized_by),
                ];
                $sql .= 'INSERT INTO public.base_database_tables '
                    .'(table_name, module_name, module_path, migration_file, stabilized_at, stabilized_by, created_at, updated_at) VALUES ('
                    .implode(', ', $values).', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP) '
                    .'ON CONFLICT (table_name) DO UPDATE SET '
                    .'module_name = EXCLUDED.module_name, module_path = EXCLUDED.module_path, '
                    .'migration_file = EXCLUDED.migration_file, stabilized_at = EXCLUDED.stabilized_at, '
                    .'stabilized_by = EXCLUDED.stabilized_by, updated_at = CURRENT_TIMESTAMP;'."\n";
            }
        }

        foreach ($deleteTables as $table) {
            $sql .= 'DELETE FROM public.base_database_tables WHERE table_name = '.$this->sqlValue($target, $table).";\n";
        }

        return $sql;
    }

    private function sqlValue(Connection $connection, mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        return $connection->getPdo()->quote((string) $value);
    }

    private function postconditionSql(Connection $target, DataShareMirrorReview $review): string
    {
        $conditions = [];

        foreach ($review->items as $item) {
            $relation = $this->sqlValue($target, 'public.'.$this->quotedIdentifier($item->table));
            $table = $this->sqlValue($target, $item->table);
            $registered = 'EXISTS (SELECT 1 FROM public.base_database_tables WHERE table_name = '.$table.')';

            if ($item->intendedAction === DataShareMirrorAction::Delete) {
                $conditions[] = 'to_regclass('.$relation.') IS NOT NULL OR '.$registered;
            } else {
                $conditions[] = 'to_regclass('.$relation.') IS NULL OR NOT '.$registered;
            }
        }

        return <<<'SQL'

            DO $blb_mirror_postcondition$
            BEGIN

            SQL
            .'    IF '.implode("\n        OR ", $conditions)." THEN\n"
            ."        RAISE EXCEPTION 'Belimbing mirror postcondition failed';\n"
            ."    END IF;\n"
            ."END\n"
            .'$blb_mirror_postcondition$;'."\n";
    }

    /** @param array<string, mixed> $configuration @return array<string, string> */
    private function processEnvironment(array $configuration): array
    {
        return [
            'PGHOST' => (string) ($configuration['host'] ?? '127.0.0.1'),
            'PGPORT' => (string) ($configuration['port'] ?? '5432'),
            'PGUSER' => (string) ($configuration['username'] ?? ''),
            'PGDATABASE' => (string) ($configuration['database'] ?? ''),
            'PGPASSWORD' => (string) ($configuration['password'] ?? ''),
            'PGSSLMODE' => (string) ($configuration['sslmode'] ?? 'prefer'),
            'PGCONNECT_TIMEOUT' => (string) ($configuration['connect_timeout'] ?? '15'),
            'PGAPPNAME' => 'belimbing-data-share-mirror',
        ];
    }

    private function quotedIdentifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_$]{0,62}$/', $identifier) !== 1) {
            throw DataShareMirrorException::invalidSelection(__('Mirror selections must contain valid PostgreSQL table names.'));
        }

        return '"'.str_replace('"', '""', $identifier).'"';
    }

    private function timeout(): int
    {
        return max(30, min(7200, (int) config('data_share.mirror.timeout_seconds', self::DEFAULT_TIMEOUT)));
    }
}
