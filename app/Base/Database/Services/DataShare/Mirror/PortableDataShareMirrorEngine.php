<?php

namespace App\Base\Database\Services\DataShare\Mirror;

use App\Base\Database\Contracts\DataShareMirrorEngine;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorExecutionResult;
use App\Base\Database\DTO\DataShare\Mirror\DataShareMirrorReview;
use App\Base\Database\Exceptions\DataShareMirrorException;
use App\Base\Database\Services\DataShare\CanonicalJson;
use App\Base\Database\Services\DataShare\DataShareSettings;
use Carbon\CarbonImmutable;
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
    ) {}

    public function mode(): string
    {
        return 'portable';
    }

    public function execute(DataShareMirrorReview $review): DataShareMirrorExecutionResult
    {
        if ($review->hasBlockers) {
            throw DataShareMirrorException::blocked();
        }

        $source = $this->connections->source($review->direction)->connection;
        $target = $this->connections->target($review->direction)->connection;
        $tables = array_values(array_map(fn ($item): string => $item->table, $review->items));
        $maximumTables = $this->settings->integer('data_share.transfer_limits.max_tables', 250, 1, 10000);
        if (count($tables) > $maximumTables) {
            throw DataShareMirrorException::limitExceeded(__('The mirror selection exceeds the :max table limit.', ['max' => $maximumTables]));
        }
        $order = $this->dependencies->insertionOrder($source, $tables);
        if ($order === null) {
            throw DataShareMirrorException::blocked();
        }

        $snapshotPath = $this->temporarySnapshotPath();

        try {
            try {
                $snapshot = $this->writeSnapshot($source, $target, $order, $snapshotPath);
            } catch (DataShareMirrorException $exception) {
                throw $exception;
            } catch (Throwable $exception) {
                throw DataShareMirrorException::safeFailure(__('The portable source snapshot could not be completed. No destination rows were changed.'), $exception);
            }

            $this->replaceTargetRows($target, $order, $snapshotPath, $snapshot['counts'], $snapshot['hashes']);

            return new DataShareMirrorExecutionResult(
                $review->direction,
                ['create' => 0, 'replace' => count($tables), 'delete' => 0],
                array_map(fn (string $table): array => ['table' => $table, 'action' => 'replace'], $tables),
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

    /** @param list<string> $tables @return array{counts: array<string, int>, hashes: array<string, string>} */
    private function writeSnapshot(Connection $source, Connection $target, array $tables, string $path): array
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw DataShareMirrorException::safeFailure(__('The portable snapshot could not be opened.'));
        }

        @chmod($path, 0600);
        $counts = array_fill_keys($tables, 0);
        $hashContexts = [];
        foreach ($tables as $table) {
            $hashContexts[$table] = hash_init('sha256');
        }
        $totalRecords = 0;
        $totalBytes = 0;
        $maximumRecords = $this->settings->integer('data_share.transfer_limits.max_records', 250000, 1, 10000000);
        $maximumScalarBytes = $this->settings->integer('data_share.transfer_limits.max_scalar_bytes', 10 * 1024 * 1024, 1, 2147483647);
        $maximumLineBytes = $this->settings->integer('data_share.transfer_limits.max_record_line_bytes', 32 * 1024 * 1024, 1, 2147483647);
        $maximumSnapshotBytes = $this->settings->integer('data_share.transfer_limits.max_package_bytes', 250 * 1024 * 1024, 1, 2147483647);

        try {
            $source->transaction(function () use ($source, $target, $tables, $handle, &$counts, $hashContexts, &$totalRecords, &$totalBytes, $maximumRecords, $maximumScalarBytes, $maximumLineBytes, $maximumSnapshotBytes): void {
                if ($source->getDriverName() === 'pgsql') {
                    $source->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ READ ONLY');
                }

                foreach ($tables as $table) {
                    $targetTypes = $this->columnTypes($target, $table);
                    $query = $source->table($table);
                    foreach ($this->schemas->primaryKey($source, $table) as $column) {
                        $query->orderBy($column);
                    }

                    foreach ($query->cursor() as $record) {
                        $row = [];
                        foreach ((array) $record as $column => $value) {
                            if (is_string($value) && strlen($value) > $maximumScalarBytes) {
                                throw DataShareMirrorException::limitExceeded(__('Table :table column :column exceeds the :max byte mirror scalar limit.', [
                                    'table' => $table,
                                    'column' => $column,
                                    'max' => $maximumScalarBytes,
                                ]));
                            }
                            $row[$column] = $this->encodeValue($value, $targetTypes[$column] ?? '');
                        }
                        ksort($row, SORT_STRING);
                        hash_update($hashContexts[$table], CanonicalJson::encode($row)."\n");

                        $line = json_encode(['table' => $table, 'row' => $row], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n";
                        $lineBytes = strlen($line);
                        if ($lineBytes > $maximumLineBytes) {
                            throw DataShareMirrorException::limitExceeded(__('A mirror record exceeds the :max byte line limit.', ['max' => $maximumLineBytes]));
                        }
                        if (++$totalRecords > $maximumRecords) {
                            throw DataShareMirrorException::limitExceeded(__('The mirror selection exceeds the :max record limit.', ['max' => $maximumRecords]));
                        }
                        $totalBytes += $lineBytes;
                        if ($totalBytes > $maximumSnapshotBytes) {
                            throw DataShareMirrorException::limitExceeded(__('The mirror snapshot exceeds the :max byte limit.', ['max' => $maximumSnapshotBytes]));
                        }
                        if (fwrite($handle, $line) !== strlen($line)) {
                            throw DataShareMirrorException::safeFailure(__('The portable snapshot could not be written.'));
                        }

                        $counts[$table]++;
                    }
                }
            }, 1);
        } finally {
            fclose($handle);
        }

        $hashes = [];
        foreach ($hashContexts as $table => $context) {
            $hashes[$table] = hash_final($context);
        }

        return ['counts' => $counts, 'hashes' => $hashes];
    }

    /** @param list<string> $tables @param array<string, int> $expectedCounts @param array<string, string> $expectedHashes */
    private function replaceTargetRows(Connection $target, array $tables, string $path, array $expectedCounts, array $expectedHashes): void
    {
        $target->transaction(function () use ($target, $tables, $path, $expectedCounts, $expectedHashes): void {
            foreach (array_reverse($tables) as $table) {
                $target->table($table)->delete();
            }

            $handle = fopen($path, 'rb');
            if ($handle === false) {
                throw DataShareMirrorException::safeFailure(__('The portable snapshot could not be read.'));
            }

            try {
                $activeTable = null;
                $chunk = [];
                while (($line = fgets($handle)) !== false) {
                    try {
                        $record = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
                    } catch (JsonException $exception) {
                        throw DataShareMirrorException::safeFailure(__('The portable snapshot is invalid.'), $exception);
                    }

                    $table = (string) ($record['table'] ?? '');
                    if ($activeTable !== null && ($activeTable !== $table || count($chunk) >= self::INSERT_CHUNK_SIZE)) {
                        $target->table($activeTable)->insert($chunk);
                        $chunk = [];
                    }

                    $activeTable = $table;
                    $chunk[] = $this->decodeRow((array) ($record['row'] ?? []));
                }

                if ($activeTable !== null && $chunk !== []) {
                    $target->table($activeTable)->insert($chunk);
                }
            } finally {
                fclose($handle);
            }

            foreach ($tables as $table) {
                $actual = (int) $target->table($table)->count();
                if ($actual !== $expectedCounts[$table]) {
                    throw DataShareMirrorException::safeFailure(__('Portable mirror row-count verification failed. The destination transaction was rolled back.'));
                }

                if (! hash_equals($expectedHashes[$table], $this->tableHash($target, $table))) {
                    throw DataShareMirrorException::safeFailure(__('Portable mirror content verification failed. The destination transaction was rolled back.'));
                }

                $this->resetSequence($target, $table);
            }
        }, 1);
    }

    /** @return array<string, string> */
    private function columnTypes(Connection $connection, string $table): array
    {
        $types = [];
        foreach ($connection->getSchemaBuilder()->getColumns($table) as $column) {
            $types[(string) $column['name']] = mb_strtolower((string) ($column['type'] ?? $column['type_name'] ?? ''));
        }

        return $types;
    }

    private function encodeValue(mixed $value, string $targetType): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value) && (! mb_check_encoding($value, 'UTF-8') || $this->schemas->portableType($targetType) === 'binary')) {
            return ['__data_share_binary_base64' => base64_encode($value)];
        }

        return match ($this->schemas->portableType($targetType)) {
            'boolean' => (bool) $value,
            'integer' => (int) $value,
            'decimal' => $this->decimal($value),
            'date' => CarbonImmutable::parse((string) $value, 'UTC')->format('Y-m-d'),
            'datetime' => $this->datetime($value),
            'textual' => str_contains($targetType, 'json') ? $this->json($value) : $value,
            default => $value,
        };
    }

    private function decimal(mixed $value): string
    {
        $decimal = strtolower(trim((string) $value));
        if (str_contains($decimal, 'e')) {
            $decimal = rtrim(rtrim(sprintf('%.15F', (float) $decimal), '0'), '.');
        }

        $negative = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '+-');
        [$integer, $fraction] = array_pad(explode('.', $decimal, 2), 2, '');
        $integer = ltrim($integer, '0');
        $integer = $integer === '' ? '0' : $integer;
        $fraction = rtrim($fraction, '0');
        $normalized = $integer.($fraction === '' ? '' : '.'.$fraction);

        return $negative && $normalized !== '0' ? '-'.$normalized : $normalized;
    }

    private function datetime(mixed $value): string
    {
        $formatted = CarbonImmutable::parse((string) $value, 'UTC')->format('Y-m-d H:i:s.u');

        return str_ends_with($formatted, '.000000') ? substr($formatted, 0, -7) : $formatted;
    }

    private function json(mixed $value): string
    {
        try {
            return CanonicalJson::encode(is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value);
        } catch (JsonException) {
            throw DataShareMirrorException::invalidSelection(__('A selected table contains invalid JSON data.'));
        }
    }

    private function tableHash(Connection $connection, string $table): string
    {
        $types = $this->columnTypes($connection, $table);
        $query = $connection->table($table);
        foreach ($this->schemas->primaryKey($connection, $table) as $column) {
            $query->orderBy($column);
        }

        $context = hash_init('sha256');
        foreach ($query->cursor() as $record) {
            $row = [];
            foreach ((array) $record as $column => $value) {
                $row[$column] = $this->encodeValue($value, $types[$column] ?? '');
            }
            ksort($row, SORT_STRING);
            hash_update($context, CanonicalJson::encode($row)."\n");
        }

        return hash_final($context);
    }

    /** @param array<string, mixed> $row @return array<string, mixed> */
    private function decodeRow(array $row): array
    {
        foreach ($row as $column => $value) {
            if (is_array($value) && array_keys($value) === ['__data_share_binary_base64']) {
                $decoded = base64_decode((string) $value['__data_share_binary_base64'], true);
                if ($decoded === false) {
                    throw DataShareMirrorException::safeFailure(__('The portable snapshot contains invalid binary data.'));
                }

                $row[$column] = $decoded;
            }
        }

        return $row;
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
