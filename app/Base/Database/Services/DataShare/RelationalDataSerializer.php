<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\DataShareScopeDefinition;
use App\Base\Database\DTO\DataShare\DataShareTableDefinition;
use App\Base\Database\DTO\DataShare\SerializedDataShareScope;
use App\Base\Database\DTO\DataShare\SerializedTablePayload;
use App\Base\Database\Exceptions\DataShareDefinitionException;
use App\Base\Database\Exceptions\DataSharePackageException;
use App\Base\Database\Exceptions\DataSharePackageStorageException;
use Illuminate\Support\Facades\DB;
use Throwable;

class RelationalDataSerializer
{
    private int $recordCount = 0;

    public function __construct(
        private readonly DataShareSchemaFingerprint $schemaFingerprint,
        private readonly DataShareValueNormalizer $values,
        private readonly DataShareSettings $settings,
    ) {}

    public function serialize(DataShareScopeDefinition $scope): SerializedDataShareScope
    {
        $this->recordCount = 0;
        $payloads = [];

        try {
            foreach ($scope->tables as $table) {
                if ($table->primaryKeyColumns === []) {
                    throw DataShareDefinitionException::invalid(__('table :table has no primary key and cannot be shared safely.', [
                        'table' => $table->table,
                    ]));
                }

                $payloads[] = $this->serializeTable($table);
            }
        } catch (Throwable $e) {
            foreach ($payloads as $payload) {
                @unlink($payload->path);
            }

            throw $e;
        }

        return new SerializedDataShareScope($payloads, [
            'tables' => count($payloads),
            'records' => $this->recordCount,
        ]);
    }

    private function serializeTable(DataShareTableDefinition $table): SerializedTablePayload
    {
        $path = $this->temporaryPath();
        $stream = fopen($path, 'wb');

        if ($stream === false) {
            throw DataSharePackageStorageException::temporaryStorageOpenFailed();
        }

        $records = 0;

        try {
            $schema = $this->schemaFingerprint->forTable($table);
            $this->writeLine($stream, [
                'kind' => 'table',
                'name' => $table->table,
                'primary_key_columns' => $table->primaryKeyColumns,
                'schema' => $schema['schema'],
                'schema_sha256' => $schema['sha256'],
            ]);
            $query = DB::table($table->table);

            foreach ($table->primaryKeyColumns as $column) {
                $query->orderBy($column);
            }

            $maximumRecords = $this->settings->integer('data_share.transfer_limits.max_records', 250000, 1, 10000000);

            foreach ($query->cursor() as $databaseRow) {
                $row = (array) $databaseRow;
                $encoded = [];

                foreach ($row as $column => $value) {
                    $this->guardScalar($table->table, $column, $value);
                    $encoded[$column] = $this->values->encode($table->table, $column, $value);
                }

                ksort($encoded, SORT_STRING);
                $primaryKey = array_intersect_key($encoded, array_fill_keys($table->primaryKeyColumns, true));
                ksort($primaryKey, SORT_STRING);
                $this->writeLine($stream, [
                    'fingerprint' => hash('sha256', CanonicalJson::encode($encoded)),
                    'kind' => 'row',
                    'primary_key' => $primaryKey,
                    'table' => $table->table,
                    'values' => $encoded,
                ]);
                $records++;

                if (++$this->recordCount > $maximumRecords) {
                    throw DataSharePackageException::tooManyRecords($maximumRecords);
                }
            }

            $this->writeLine($stream, ['kind' => 'table_end', 'name' => $table->table]);
        } catch (Throwable $e) {
            fclose($stream);
            @unlink($path);
            throw $e;
        }

        fclose($stream);
        $bytes = filesize($path);
        $sha256 = hash_file('sha256', $path);

        if ($bytes === false || $sha256 === false) {
            @unlink($path);
            throw DataSharePackageStorageException::payloadInspectionFailed();
        }

        return new SerializedTablePayload($path, [
            'table' => $table->table,
            'schema_sha256' => $schema['sha256'],
            'sha256' => $sha256,
            'bytes' => $bytes,
            'records' => $records,
        ]);
    }

    private function guardScalar(string $table, string $column, mixed $value): void
    {
        if (! is_string($value)) {
            return;
        }

        $max = $this->settings->integer('data_share.transfer_limits.max_scalar_bytes', 10 * 1024 * 1024, 1, 2147483647);

        if (strlen($value) > $max) {
            throw DataSharePackageException::scalarTooLarge($table, $column, $max);
        }
    }

    private function temporaryPath(): string
    {
        $path = tempnam(sys_get_temp_dir(), 'blb-data-export-table-');

        if ($path === false) {
            throw DataSharePackageStorageException::temporaryStorageUnavailable();
        }

        @chmod($path, 0600);

        return $path;
    }

    /** @param resource $stream */
    private function writeLine($stream, array $value): void
    {
        $line = CanonicalJson::encode($value)."\n";
        $maximum = $this->settings->integer('data_share.transfer_limits.max_record_line_bytes', 32 * 1024 * 1024, 1, 2147483647);

        if (strlen($line) > $maximum) {
            throw DataSharePackageException::recordLineTooLarge($maximum);
        }

        if (fwrite($stream, $line) !== strlen($line)) {
            throw DataSharePackageStorageException::payloadWriteFailed();
        }
    }
}
