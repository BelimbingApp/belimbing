<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Contracts\DataShareMirrorEngine;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorExecutionResult;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorProgress;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReview;
use App\Base\Database\DTO\DataShare\Mirror\PortableDataShareMirrorSnapshotState;
use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Services\DataShare\CanonicalJson;
use App\Base\Database\Services\DataShare\DataShareSettings;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\Filesystem;
use JsonException;
use Throwable;

class PortableDataShareMirrorEngine implements DataShareMirrorEngine
{
    private const INSERT_CHUNK_SIZE = 500;

    public function __construct(
        private readonly DataShareMirrorConnectionManager $connections,
        private readonly DataShareMirrorDependencyInspector $dependencies,
        private readonly DataShareMirrorSchemaComparator $schemas,
        private readonly DataShareMirrorTemporaryFiles $temporaryFiles,
        private readonly Filesystem $files,
        private readonly DataShareSettings $settings,
        private readonly PortableDataShareMirrorValueCodec $codec,
    ) {}

    public function mode(): string
    {
        return 'portable';
    }

    public function execute(DataShareMirrorReview $review, ?DataShareMirrorProgress $progress = null): DataShareMirrorExecutionResult
    {
        if ($review->hasBlockers) {
            throw DataShareMirrorException::blocked();
        }

        $sourceEndpoint = $this->connections->source($review->direction);
        $targetEndpoint = $this->connections->target($review->direction);
        $source = $sourceEndpoint->connection;
        $target = $targetEndpoint->connection;
        $tables = array_values(array_map(fn ($item): string => $item->table, $review->items));
        $order = $this->validatedInsertionOrder($source, $tables);
        $tableCount = count($tables);
        $progress?->report((string) trans_choice(
            'Staging :count selected table from :source.|Staging :count selected tables from :source.',
            $tableCount,
            ['count' => $tableCount, 'source' => $sourceEndpoint->label],
        ));

        $snapshotPath = $this->temporarySnapshotPath();

        try {
            $snapshot = $this->stageSnapshot($source, $target, $order, $snapshotPath, $sourceEndpoint->label, $targetEndpoint->label, $progress);

            $progress?->report((string) __('Staging from :source complete: :records rows, :bytes bytes.', [
                'source' => $sourceEndpoint->label,
                'records' => $snapshot['records'],
                'bytes' => $snapshot['bytes'],
            ]));
            $progress?->report((string) __('Writing staged data to :target in one transaction.', ['target' => $targetEndpoint->label]));
            $this->replaceTargetRows($target, $order, $snapshotPath, $snapshot['counts'], $snapshot['hashes'], $targetEndpoint->label, $progress);
            $progress?->report((string) __('Changes committed to :target.', ['target' => $targetEndpoint->label]));

            return new DataShareMirrorExecutionResult(
                $review->direction,
                ['create' => 0, 'replace' => count($tables), 'delete' => 0],
                array_map(fn (string $table): array => [
                    'table' => $table,
                    'action' => 'replace',
                    'local_rows' => $snapshot['counts'][$table],
                    'remote_rows' => $snapshot['counts'][$table],
                ], $tables),
            );
        } finally {
            if (is_file($snapshotPath)) {
                try {
                    $this->files->delete($snapshotPath);
                } catch (Throwable) {
                    @unlink($snapshotPath);
                }
            }
        }
    }

    /** @param list<string> $tables @return list<string> */
    private function validatedInsertionOrder(Connection $source, array $tables): array
    {
        $maximumTables = $this->settings->integer('data_share.transfer_limits.max_tables', 250, 1, 10000);
        if (count($tables) > $maximumTables) {
            throw DataShareMirrorException::limitExceeded(__('The mirror selection exceeds the :max table limit.', ['max' => $maximumTables]));
        }

        $order = $this->dependencies->insertionOrder($source, $tables);
        if ($order === null) {
            throw DataShareMirrorException::blocked();
        }

        return $order;
    }

    /** @param list<string> $tables @return array{counts: array<string, int>, hashes: array<string, string>, records: int, bytes: int} */
    private function stageSnapshot(Connection $source, Connection $target, array $tables, string $path, string $sourceLabel, string $targetLabel, ?DataShareMirrorProgress $progress): array
    {
        try {
            return $this->writeSnapshot($source, $target, $tables, $path, $sourceLabel, $progress);
        } catch (DataShareMirrorException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            throw DataShareMirrorException::safeFailure(__('Source data could not be staged. No rows in :target were changed.', ['target' => $targetLabel]), $exception);
        }
    }

    /** @param list<string> $tables @return array{counts: array<string, int>, hashes: array<string, string>, records: int, bytes: int} */
    private function writeSnapshot(Connection $source, Connection $target, array $tables, string $path, string $sourceLabel, ?DataShareMirrorProgress $progress): array
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw DataShareMirrorException::safeFailure(__('The temporary staging file could not be opened.'));
        }

        @chmod($path, 0600);
        $state = $this->newSnapshotState($tables, $sourceLabel, $progress);

        // A REPEATABLE READ, read-only snapshot gives a consistent view while
        // tables stream out. PostgreSQL requires the isolation level to be set
        // before the transaction's first query, so issue it ahead of the
        // transaction and only when we are not already inside one (an outer
        // transaction, such as a test's rollback wrapper, would otherwise fail
        // with SQLSTATE 25001).
        if ($source->getDriverName() === 'pgsql' && $source->transactionLevel() === 0) {
            $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
        }

        try {
            $source->transaction(fn () => $this->snapshotTables($source, $target, $tables, $handle, $state), 1);
        } finally {
            fclose($handle);
        }

        return $state->result();
    }

    /** @param list<string> $tables */
    private function newSnapshotState(array $tables, string $sourceLabel, ?DataShareMirrorProgress $progress): PortableDataShareMirrorSnapshotState
    {
        return new PortableDataShareMirrorSnapshotState(
            $tables,
            $this->settings->integer('data_share.transfer_limits.max_scalar_bytes', 10 * 1024 * 1024, 1, 2147483647),
            $this->settings->integer('data_share.transfer_limits.max_record_line_bytes', 32 * 1024 * 1024, 1, 2147483647),
            $this->settings->integer('data_share.mirror.max_snapshot_bytes', (int) config('data_share.mirror.max_snapshot_bytes', 1024 * 1024 * 1024), 1, 2147483647),
            $sourceLabel,
            $progress,
        );
    }

    /** @param list<string> $tables @param resource $handle */
    private function snapshotTables(Connection $source, Connection $target, array $tables, $handle, PortableDataShareMirrorSnapshotState $state): void
    {
        foreach ($tables as $index => $table) {
            $this->snapshotTable($source, $target, $table, $handle, $state);
            $state->progress?->report((string) __('Staged table :current of :total from :source: :table (:rows rows).', [
                'current' => $index + 1,
                'total' => count($tables),
                'source' => $state->sourceLabel,
                'table' => $table,
                'rows' => $state->counts[$table],
            ]));
        }
    }

    /** @param resource $handle */
    private function snapshotTable(Connection $source, Connection $target, string $table, $handle, PortableDataShareMirrorSnapshotState $state): void
    {
        $targetTypes = $this->codec->columnTypes($target, $table);
        $query = $source->table($table);
        foreach ($this->schemas->primaryKey($source, $table) as $column) {
            $query->orderBy($column);
        }

        foreach ($query->cursor() as $record) {
            $this->writeSnapshotRecord($table, (array) $record, $targetTypes, $handle, $state);
        }
    }

    /** @param array<string, mixed> $record @param array<string, string> $targetTypes @param resource $handle */
    private function writeSnapshotRecord(string $table, array $record, array $targetTypes, $handle, PortableDataShareMirrorSnapshotState $state): void
    {
        $row = [];
        foreach ($record as $column => $value) {
            if (is_string($value) && strlen($value) > $state->maximumScalarBytes) {
                throw DataShareMirrorException::limitExceeded(__('Table :table column :column exceeds the :max byte mirror scalar limit.', [
                    'table' => $table,
                    'column' => $column,
                    'max' => $state->maximumScalarBytes,
                ]));
            }
            $row[$column] = $this->codec->encode($value, $targetTypes[$column] ?? '');
        }
        ksort($row, SORT_STRING);
        hash_update($state->hashContexts[$table], CanonicalJson::encode($row)."\n");

        $line = json_encode(['table' => $table, 'row' => $row], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
        $lineBytes = strlen($line);
        if ($lineBytes > $state->maximumLineBytes) {
            throw DataShareMirrorException::limitExceeded(__('A mirror record exceeds the :max byte line limit.', ['max' => $state->maximumLineBytes]));
        }
        $state->records++;
        $state->bytes += $lineBytes;
        if ($state->bytes > $state->maximumSnapshotBytes) {
            throw DataShareMirrorException::limitExceeded(__('Mirror staging exceeds the :max byte limit.', ['max' => $state->maximumSnapshotBytes]));
        }
        if (fwrite($handle, $line) !== strlen($line)) {
            throw DataShareMirrorException::safeFailure(__('The temporary staging file could not be written.'));
        }

        $state->counts[$table]++;
    }

    /** @param list<string> $tables @param array<string, int> $expectedCounts @param array<string, string> $expectedHashes */
    private function replaceTargetRows(Connection $target, array $tables, string $path, array $expectedCounts, array $expectedHashes, string $targetLabel, ?DataShareMirrorProgress $progress): void
    {
        $target->transaction(function () use ($target, $tables, $path, $expectedCounts, $expectedHashes, $targetLabel, $progress): void {
            foreach (array_reverse($tables) as $table) {
                $target->table($table)->delete();
            }
            $progress?->report((string) __('Selected destination rows cleared inside the uncommitted transaction.'));
            $this->loadSnapshot($target, $path, $expectedCounts, $targetLabel, $progress);
            $this->verifyTarget($target, $tables, $expectedCounts, $expectedHashes, $targetLabel, $progress);
        }, 1);
    }

    /** @param array<string, int> $expectedCounts */
    private function loadSnapshot(Connection $target, string $path, array $expectedCounts, string $targetLabel, ?DataShareMirrorProgress $progress): void
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw DataShareMirrorException::safeFailure(__('The temporary staging file could not be read.'));
        }

        try {
            $activeTable = null;
            $chunk = [];
            while (($line = fgets($handle)) !== false) {
                $record = $this->decodeSnapshotRecord($line);
                $table = (string) ($record['table'] ?? '');
                if ($activeTable !== null && $activeTable !== $table) {
                    $this->flushChunk($target, $activeTable, $chunk);
                    $progress?->report((string) __('Written to :target: :table (:rows rows).', ['target' => $targetLabel, 'table' => $activeTable, 'rows' => $expectedCounts[$activeTable]]));
                    $chunk = [];
                } elseif ($activeTable !== null && count($chunk) >= self::INSERT_CHUNK_SIZE) {
                    $this->flushChunk($target, $activeTable, $chunk);
                    $chunk = [];
                }
                $activeTable = $table;
                $chunk[] = $this->codec->decodeRow((array) ($record['row'] ?? []));
            }
            if ($activeTable !== null) {
                $this->flushChunk($target, $activeTable, $chunk);
                $progress?->report((string) __('Written to :target: :table (:rows rows).', ['target' => $targetLabel, 'table' => $activeTable, 'rows' => $expectedCounts[$activeTable]]));
            }
        } finally {
            fclose($handle);
        }
    }

    /** @return array<string, mixed> */
    private function decodeSnapshotRecord(string $line): array
    {
        try {
            return (array) json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw DataShareMirrorException::safeFailure(__('The temporary staging file is invalid.'), $exception);
        }
    }

    /** @param list<array<string, mixed>> $chunk */
    private function flushChunk(Connection $target, string $table, array $chunk): void
    {
        if ($chunk !== []) {
            $target->table($table)->insert($chunk);
        }
    }

    /** @param list<string> $tables @param array<string, int> $expectedCounts @param array<string, string> $expectedHashes */
    private function verifyTarget(Connection $target, array $tables, array $expectedCounts, array $expectedHashes, string $targetLabel, ?DataShareMirrorProgress $progress): void
    {
        $tableCount = count($tables);
        foreach ($tables as $index => $table) {
            $actual = (int) $target->table($table)->count();
            if ($actual !== $expectedCounts[$table]) {
                throw DataShareMirrorException::safeFailure(__('Portable mirror row-count verification failed. The destination transaction was rolled back.'));
            }
            if (! hash_equals($expectedHashes[$table], $this->tableHash($target, $table))) {
                throw DataShareMirrorException::safeFailure(__('Portable mirror content verification failed. The destination transaction was rolled back.'));
            }
            $this->resetSequence($target, $table);
            $progress?->report((string) __('Verified table :current of :total in :target: :table (:rows rows).', ['current' => $index + 1, 'total' => $tableCount, 'target' => $targetLabel, 'table' => $table, 'rows' => $actual]));
        }
    }

    private function tableHash(Connection $connection, string $table): string
    {
        $types = $this->codec->columnTypes($connection, $table);
        $query = $connection->table($table);
        foreach ($this->schemas->primaryKey($connection, $table) as $column) {
            $query->orderBy($column);
        }

        $context = hash_init('sha256');
        foreach ($query->cursor() as $record) {
            $row = [];
            foreach ((array) $record as $column => $value) {
                $row[$column] = $this->codec->encode($value, $types[$column] ?? '');
            }
            ksort($row, SORT_STRING);
            hash_update($context, CanonicalJson::encode($row)."\n");
        }

        return hash_final($context);
    }

    private function resetSequence(Connection $connection, string $table): void
    {
        $autoIncrement = null;
        foreach ($connection->getSchemaBuilder()->getColumns($table) as $column) {
            if ((bool) ($column['auto_increment'] ?? false)) {
                $autoIncrement = (string) $column['name'];
                break;
            }
        }

        if ($autoIncrement === null) {
            return;
        }

        $maximum = $connection->table($table)->max($autoIncrement);
        if ($connection->getDriverName() === 'pgsql') {
            $sequence = $connection->selectOne('SELECT pg_get_serial_sequence(?, ?) AS sequence_name', ['public.'.$table, $autoIncrement]);
            if (is_string($sequence->sequence_name ?? null) && $sequence->sequence_name !== '') {
                $connection->select('SELECT setval(?::regclass, ?, ?)', [$sequence->sequence_name, max(1, (int) $maximum), $maximum !== null]);
            }

            return;
        }

        if ($connection->getSchemaBuilder()->hasTable('sqlite_sequence')) {
            $connection->table('sqlite_sequence')->updateOrInsert(['name' => $table], ['seq' => (int) ($maximum ?? 0)]);
        }
    }

    private function temporarySnapshotPath(): string
    {
        return $this->temporaryFiles->create('blb-portable-mirror-', '.ndjson');
    }
}
