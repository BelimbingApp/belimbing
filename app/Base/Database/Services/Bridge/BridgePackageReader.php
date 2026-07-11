<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\DTO\Bridge\BridgeScopeDefinition;
use App\Base\Database\DTO\Bridge\BridgeTableDefinition;
use App\Base\Database\DTO\Bridge\VerifiedBridgePackage;
use App\Base\Database\Exceptions\BridgePackageException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use JsonException;

class BridgePackageReader
{
    /** @var array<string, list<string>> */
    private array $columns = [];

    public function __construct(
        private readonly BridgeScopeCatalog $catalog,
        private readonly BridgeSchemaFingerprint $schemas,
        private readonly BridgeSchemaCompatibility $compatibility,
        private readonly BridgeSettings $settings,
    ) {}

    /**
     * @param  resource  $stream
     * @return array<string, mixed>
     */
    public function manifest($stream): array
    {
        $header = $this->readCanonicalLine($stream)['value'];

        if (($header['format'] ?? null) !== BridgePackageExporter::FORMAT) {
            throw BridgePackageException::unsupportedFormat((string) ($header['format'] ?? 'missing'));
        }

        $manifest = $header['manifest'] ?? null;

        if (! is_array($manifest)) {
            throw BridgePackageException::invalidPackage(__('the manifest is missing.'));
        }

        $this->validateManifestShape($manifest);

        return $manifest;
    }

    /**
     * @param  resource  $stream
     * @param  (callable(BridgeScopeDefinition, BridgeTableDefinition, array<string, mixed>): void)|null  $onRecord
     */
    public function inspect($stream, ?callable $onRecord = null): VerifiedBridgePackage
    {
        $this->columns = [];
        $packageHash = hash_init('sha256');
        $bytes = 0;
        $headerLine = $this->readCanonicalLine($stream);
        hash_update($packageHash, $headerLine['raw']);
        $bytes += strlen($headerLine['raw']);
        $manifest = $this->validatedHeaderManifest($headerLine['value']);
        $scope = $this->catalog->scope($manifest['scope']['name'], $manifest['scope']['tables']);
        $payloadMetadata = $manifest['payloads'];

        if (count($payloadMetadata) !== count($scope->tables)) {
            throw BridgePackageException::invalidPackage(__('payload membership does not match the selected export scope.'));
        }

        $recordCount = 0;
        $maximumRecords = $this->settings->integer('bridge.transfer_limits.max_records', 250000, 1, 10000000);
        $sourceDriver = (string) $manifest['source']['database_driver'];
        $destinationDriver = DB::connection()->getDriverName();

        foreach ($scope->tables as $index => $table) {
            $metadata = $payloadMetadata[$index] ?? null;

            if (! is_array($metadata) || ($metadata['table'] ?? null) !== $table->table) {
                throw BridgePackageException::invalidPackage(__('payload order does not match foreign-key dependencies.'));
            }

            $this->validatePayloadMetadata($metadata);
            $payloadHash = hash_init('sha256');
            $remaining = (int) $metadata['bytes'];
            $readLine = function () use ($stream, &$remaining, $payloadHash, $packageHash, &$bytes): array {
                return $this->readPayloadLine($stream, $remaining, $payloadHash, $packageHash, $bytes);
            };
            $destinationSchema = $this->schemas->forTable($table);
            $tableLine = $this->validatedTableHeader($readLine(), $table, $metadata);

            $this->compatibility->assertCompatible(
                $tableLine['schema'],
                $destinationSchema['schema'],
                $sourceDriver,
                $destinationDriver,
            );

            $tableRecords = $this->inspectTableRecords(
                $readLine,
                $scope,
                $table,
                $recordCount,
                $maximumRecords,
                $onRecord,
            );
            $this->validatePayloadCompletion($table, $metadata, $remaining, $tableRecords, $payloadHash);
        }

        if (fgetc($stream) !== false) {
            throw BridgePackageException::invalidPackage(__('unexpected bytes follow the declared payloads.'));
        }

        if (CanonicalJson::encode($manifest['counts']) !== CanonicalJson::encode([
            'tables' => count($scope->tables),
            'records' => $recordCount,
        ])) {
            throw BridgePackageException::invalidPackage(__('manifest counts do not match the payload.'));
        }

        return new VerifiedBridgePackage($manifest, hash_final($packageHash), $bytes);
    }

    /**
     * @param  array<string, mixed>  $header
     * @return array<string, mixed>
     */
    private function validatedHeaderManifest(array $header): array
    {
        if (($header['format'] ?? null) !== BridgePackageExporter::FORMAT) {
            throw BridgePackageException::unsupportedFormat((string) ($header['format'] ?? 'missing'));
        }

        $manifest = $header['manifest'] ?? null;

        if (! is_array($manifest)) {
            throw BridgePackageException::invalidPackage(__('the manifest is missing.'));
        }

        $this->validateManifestShape($manifest);

        return $manifest;
    }

    /**
     * @param  array<string, mixed>  $line
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function validatedTableHeader(
        array $line,
        BridgeTableDefinition $table,
        array $metadata,
    ): array {
        if (($line['kind'] ?? null) !== 'table'
            || ($line['name'] ?? null) !== $table->table
            || ($line['primary_key_columns'] ?? null) !== $table->primaryKeyColumns
            || ($line['schema_sha256'] ?? null) !== $metadata['schema_sha256']
            || ! is_array($line['schema'] ?? null)
            || ! hash_equals(
                (string) $metadata['schema_sha256'],
                hash('sha256', CanonicalJson::encode($line['schema'] ?? null)),
            )) {
            throw BridgePackageException::schemaMismatch($table->table);
        }

        return $line;
    }

    /**
     * @param  callable(): array<string, mixed>  $readLine
     * @param  (callable(BridgeScopeDefinition, BridgeTableDefinition, array<string, mixed>): void)|null  $onRecord
     */
    private function inspectTableRecords(
        callable $readLine,
        BridgeScopeDefinition $scope,
        BridgeTableDefinition $table,
        int &$recordCount,
        int $maximumRecords,
        ?callable $onRecord,
    ): int {
        $tableRecords = 0;
        $seen = [];

        while (true) {
            $line = $readLine();

            if (($line['kind'] ?? null) === 'table_end') {
                $this->validateTableTerminator($line, $table);

                break;
            }

            $keyHash = hash('sha256', $this->validateRecord($line, $table));

            if (isset($seen[$keyHash])) {
                throw BridgePackageException::duplicatePrimaryKey($table->table);
            }

            $seen[$keyHash] = true;
            $recordCount++;
            $tableRecords++;

            if ($recordCount > $maximumRecords) {
                throw BridgePackageException::tooManyRecords($maximumRecords);
            }

            if ($onRecord !== null) {
                $onRecord($scope, $table, $line);
            }
        }

        return $tableRecords;
    }

    /** @param array<string, mixed> $line */
    private function validateTableTerminator(array $line, BridgeTableDefinition $table): void
    {
        if ($line !== ['kind' => 'table_end', 'name' => $table->table]) {
            throw BridgePackageException::invalidPackage(__('table terminator is malformed.'));
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @param  resource  $payloadHash
     */
    private function validatePayloadCompletion(
        BridgeTableDefinition $table,
        array $metadata,
        int $remaining,
        int $tableRecords,
        $payloadHash,
    ): void {
        if ($remaining !== 0
            || $tableRecords !== (int) $metadata['records']
            || ! hash_equals((string) $metadata['sha256'], hash_final($payloadHash))) {
            throw BridgePackageException::payloadHashMismatch($table->table);
        }
    }

    /** @param array<string, mixed> $manifest */
    private function validateManifestShape(array $manifest): void
    {
        $packageId = $manifest['package_id'] ?? null;
        $scope = $manifest['scope']['name'] ?? null;
        $tables = $manifest['scope']['tables'] ?? null;
        $sourceId = $manifest['source']['id'] ?? null;
        $targetId = $manifest['target']['id'] ?? null;
        $grantId = $manifest['receive_grant_id'] ?? null;

        if (! is_string($packageId) || preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $packageId) !== 1
            || ! is_string($scope) || $scope === ''
            || ! is_array($tables) || $tables === []
            || ! is_string($sourceId) || $sourceId === ''
            || ! is_string($targetId) || $targetId === ''
            || ! is_string($grantId) || preg_match('/^[0-9a-hjkmnp-tv-z]{26}$/', $grantId) !== 1
            || ! is_string($manifest['source']['database_driver'] ?? null)
            || ! is_array($manifest['payloads'] ?? null)
            || ! is_array($manifest['counts'] ?? null)
            || ! is_string($manifest['created_at'] ?? null)
            || ! is_string($manifest['expires_at'] ?? null)
            || ($manifest['conflict_policy'] ?? null) !== 'reject') {
            throw BridgePackageException::invalidPackage(__('required manifest identity, scope, or count fields are malformed.'));
        }

        $maximumTables = $this->settings->integer('bridge.transfer_limits.max_tables', 250, 1, 10000);

        if (count($tables) > $maximumTables) {
            throw BridgePackageException::tooManyTables($maximumTables);
        }
    }

    /** @param array<string, mixed> $metadata */
    private function validatePayloadMetadata(array $metadata): void
    {
        if (! is_int($metadata['bytes'] ?? null) || $metadata['bytes'] < 1
            || ! is_int($metadata['records'] ?? null) || $metadata['records'] < 0
            || ! is_string($metadata['sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $metadata['sha256']) !== 1
            || ! is_string($metadata['schema_sha256'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $metadata['schema_sha256']) !== 1) {
            throw BridgePackageException::invalidPackage(__('payload metadata is malformed.'));
        }
    }

    /** @param array<string, mixed> $record */
    private function validateRecord(array $record, BridgeTableDefinition $table): string
    {
        if (($record['kind'] ?? null) !== 'row'
            || ($record['table'] ?? null) !== $table->table
            || ! is_array($record['primary_key'] ?? null)
            || ! is_array($record['values'] ?? null)
            || ! is_string($record['fingerprint'] ?? null)) {
            throw BridgePackageException::invalidPackage(__('a row envelope is malformed.'));
        }

        $columns = $this->columns[$table->table] ??= Schema::getColumnListing($table->table);
        $valueColumns = array_keys($record['values']);
        sort($columns, SORT_STRING);
        sort($valueColumns, SORT_STRING);
        $keyColumns = array_keys($record['primary_key']);
        sort($keyColumns, SORT_STRING);
        $expectedKeyColumns = $table->primaryKeyColumns;
        sort($expectedKeyColumns, SORT_STRING);

        if ($valueColumns !== $columns || $keyColumns !== $expectedKeyColumns) {
            throw BridgePackageException::invalidPackage(__('a row does not contain the exact destination column contract.'));
        }

        foreach ($record['primary_key'] as $column => $value) {
            if (($record['values'][$column] ?? null) !== $value) {
                throw BridgePackageException::invalidPackage(__('a row primary key differs from its values.'));
            }
        }

        if (! hash_equals(hash('sha256', CanonicalJson::encode($record['values'])), $record['fingerprint'])) {
            throw BridgePackageException::invalidPackage(__('a row fingerprint is invalid.'));
        }

        return CanonicalJson::encode($record['primary_key']);
    }

    /**
     * @param  resource  $stream
     * @return array{raw: string, value: array<string, mixed>}
     */
    private function readCanonicalLine($stream): array
    {
        $max = $this->settings->integer('bridge.transfer_limits.max_record_line_bytes', 32 * 1024 * 1024, 1, 2147483647);
        $raw = fgets($stream, $max + 2);

        if ($raw === false || ! str_ends_with($raw, "\n")) {
            throw BridgePackageException::invalidPackage(__('a canonical line is truncated.'));
        }

        if (strlen($raw) > $max) {
            throw BridgePackageException::recordLineTooLarge($max);
        }

        try {
            $value = json_decode(substr($raw, 0, -1), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw BridgePackageException::invalidPackage(__('a canonical line is not valid JSON.'));
        }

        if (! is_array($value) || ! hash_equals(CanonicalJson::encode($value)."\n", $raw)) {
            throw BridgePackageException::invalidPackage(__('a line is not in canonical form.'));
        }

        return ['raw' => $raw, 'value' => $value];
    }

    /**
     * @param  resource  $stream
     * @param  resource  $payloadHash
     * @param  resource  $packageHash
     * @return array<string, mixed>
     */
    private function readPayloadLine($stream, int &$remaining, $payloadHash, $packageHash, int &$bytes): array
    {
        if ($remaining < 1) {
            throw BridgePackageException::invalidPackage(__('payload ended before its terminator.'));
        }

        $line = $this->readCanonicalLine($stream);
        $length = strlen($line['raw']);

        if ($length > $remaining) {
            throw BridgePackageException::invalidPackage(__('a payload exceeds its declared byte count.'));
        }

        $remaining -= $length;
        $bytes += $length;
        hash_update($payloadHash, $line['raw']);
        hash_update($packageHash, $line['raw']);

        return $line['value'];
    }
}
