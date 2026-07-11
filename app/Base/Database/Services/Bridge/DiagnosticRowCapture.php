<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\Exceptions\BridgeCaptureException;
use App\Base\Database\Services\TableInspector;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Connection;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDO;
use Throwable;

/**
 * Builds byte-exact diagnostic capture packages from selected rows.
 *
 * Diagnostic packages exist to reproduce data-shaped production bugs on a
 * development instance. They are deliberately not canonicalized: string
 * values keep their exact bytes (RTL marks, zero-width characters, non-NFC
 * sequences) because normalization can erase the value that triggers the
 * bug. Packages carry a marker that development-only importers require and
 * production importers must categorically refuse.
 */
class DiagnosticRowCapture
{
    public const FORMAT = 'blb-diagnostic-capture';

    public const FORMAT_VERSION = 1;

    private const DEFAULT_PATH_PREFIX = 'bridge/diagnostics';

    public function __construct(
        private TableInspector $inspector,
        private DependencyClosureResolver $closure,
        private ColumnRedactor $redactor,
        private FilesystemManager $disks,
        private BridgeSettings $settings,
    ) {}

    public function primaryKeyColumn(string $table): ?string
    {
        return $this->closure->primaryKeyColumn($table);
    }

    /**
     * Resolve and summarize the capture without writing anything.
     *
     * @param  list<int|string>  $ids
     * @return array{tables: list<array{table: string, depth: int, row_count: int, redacted_columns: list<string>}>, total_rows: int, selected_rows: int, payload_size_bytes: int, preview_sha256: string, source: array<string, mixed>}
     */
    public function preview(string $table, array $ids): array
    {
        $ids = $this->normalizeIds($ids);
        $tables = $this->buildTables($table, $ids);
        $payload = CanonicalJson::encode($tables);

        $this->guardPackageSize(strlen($payload));

        return [
            'tables' => array_map(fn (array $entry) => [
                'table' => $entry['table'],
                'depth' => $entry['depth'],
                'row_count' => count($entry['rows']),
                'redacted_columns' => $entry['redacted_columns'],
            ], $tables),
            'total_rows' => array_sum(array_map(fn (array $entry) => count($entry['rows']), $tables)),
            'selected_rows' => count($ids),
            'payload_size_bytes' => strlen($payload),
            'preview_sha256' => hash('sha256', $payload),
            'source' => $this->sourceProvenance(),
        ];
    }

    /**
     * Build the package and write it to the configured non-public disk.
     *
     * @param  list<int|string>  $ids
     * @return array{package_id: string, path: string, payload_sha256: string, total_rows: int, size_bytes: int}
     */
    public function capture(string $table, array $ids, string $trigger, string $previewSha256): array
    {
        $disk = $this->disk();
        $ids = $this->normalizeIds($ids);
        $tables = $this->buildTables($table, $ids);
        $primaryKey = $this->primaryKeyColumn($table);

        $payload = CanonicalJson::encode($tables);
        $payloadSha256 = hash('sha256', $payload);

        $this->guardPackageSize(strlen($payload));

        if (! hash_equals($previewSha256, $payloadSha256)) {
            throw BridgeCaptureException::previewChanged();
        }

        $packageId = 'diag-'.strtolower((string) Str::ulid());
        $totalRows = array_sum(array_map(fn (array $entry) => count($entry['rows']), $tables));

        $package = [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'package_id' => $packageId,
            'marker' => 'diagnostic',
            'import_policy' => 'development-only',
            'created_at' => now()->toIso8601String(),
            'trigger' => $trigger,
            'source' => $this->sourceProvenance(),
            'selection' => [
                'table' => $table,
                'primary_key' => $primaryKey,
                'ids' => array_values($ids),
            ],
            'counts' => [
                'tables' => count($tables),
                'rows' => $totalRows,
            ],
            'payload_sha256' => $payloadSha256,
            'tables' => $tables,
        ];

        $json = CanonicalJson::encode($package);
        $path = $this->settings->pathPrefix('bridge.path_prefix', self::DEFAULT_PATH_PREFIX)
            .'/'.now()->format('Ymd_His').'-'.$packageId.'.json';

        $this->guardPackageSize(strlen($json));

        if (! $disk->put($path, $json)) {
            throw BridgeCaptureException::storeFailed($path);
        }

        return [
            'package_id' => $packageId,
            'path' => $path,
            'payload_sha256' => $package['payload_sha256'],
            'total_rows' => $totalRows,
            'size_bytes' => strlen($json),
        ];
    }

    /**
     * Summaries of every package on the configured disk, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function listPackages(): array
    {
        $disk = $this->disk();
        $prefix = $this->settings->pathPrefix('bridge.path_prefix', self::DEFAULT_PATH_PREFIX);
        $maxPackageBytes = $this->settings->integer('bridge.limits.max_package_bytes', 25 * 1024 * 1024, 1, 2147483647);
        $packages = [];

        foreach ($disk->files($prefix) as $file) {
            if (! str_ends_with($file, '.json')) {
                continue;
            }

            try {
                $size = $disk->size($file);

                if ($size > $maxPackageBytes) {
                    continue;
                }

                $data = json_decode((string) $disk->get($file), true);
            } catch (Throwable) {
                continue;
            }

            if (! is_array($data)
                || ($data['format'] ?? null) !== self::FORMAT
                || ($data['marker'] ?? null) !== 'diagnostic'
                || ! is_string($data['package_id'] ?? null)
                || ! str_starts_with($data['package_id'], 'diag-')) {
                continue;
            }

            $packages[] = [
                'path' => $file,
                'package_id' => (string) ($data['package_id'] ?? ''),
                'root_table' => (string) ($data['selection']['table'] ?? ''),
                'selected' => count($data['selection']['ids'] ?? []),
                'tables' => (int) ($data['counts']['tables'] ?? 0),
                'rows' => (int) ($data['counts']['rows'] ?? 0),
                'payload_sha256_short' => substr((string) ($data['payload_sha256'] ?? ''), 0, 12),
                'created_at' => (string) ($data['created_at'] ?? ''),
                'size_bytes' => $size,
            ];
        }

        usort($packages, fn (array $a, array $b) => strcmp($b['created_at'], $a['created_at']));

        return $packages;
    }

    public function deletePackage(string $path): bool
    {
        $prefix = $this->settings->pathPrefix('bridge.path_prefix', self::DEFAULT_PATH_PREFIX);

        if (! str_starts_with($path, $prefix.'/') || str_contains($path, '..')) {
            return false;
        }

        $disk = $this->disk();

        if (! $disk->exists($path)) {
            return false;
        }

        return $disk->delete($path);
    }

    /**
     * Resolve, redact, and byte-safe-encode the closure for a selection.
     *
     * @param  list<int|string>  $ids
     * @return list<array{table: string, depth: int, primary_key: string|null, redacted_columns: list<string>, rows: list<array<string, mixed>>}>
     */
    private function buildTables(string $table, array $ids): array
    {
        if ($ids === []) {
            throw BridgeCaptureException::noSelection();
        }

        $maxSelected = $this->settings->integer('bridge.limits.max_selected_rows', 100, 1, 10000);

        if (count($ids) > $maxSelected) {
            throw BridgeCaptureException::tooManySelected($maxSelected);
        }

        // Guards registration and relation existence; throws 404 otherwise.
        $this->inspector->columns($table);

        $primaryKey = $this->primaryKeyColumn($table);

        if ($primaryKey === null) {
            throw BridgeCaptureException::noPrimaryKey($table);
        }

        $entries = [];

        foreach ($this->closure->resolve($table, $primaryKey, $ids) as $entry) {
            $redaction = $this->redactor->redact($entry['table'], $entry['rows']);

            $entries[] = [
                'table' => $entry['table'],
                'depth' => $entry['depth'],
                'primary_key' => $entry['primary_key'],
                'redacted_columns' => $redaction['redacted_columns'],
                'rows' => array_map(
                    fn (array $row) => $this->encodeRow($entry['table'], $row),
                    $redaction['rows'],
                ),
            ];
        }

        return $entries;
    }

    /**
     * Keep values byte-exact in JSON: valid UTF-8 strings pass through
     * unescaped; anything else is wrapped as base64.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function encodeRow(string $table, array $row): array
    {
        $maxScalarBytes = $this->settings->integer('bridge.limits.max_scalar_bytes', 5 * 1024 * 1024, 1, 2147483647);

        foreach ($row as $column => $value) {
            if (! is_string($value)) {
                continue;
            }

            if (strlen($value) > $maxScalarBytes) {
                throw BridgeCaptureException::scalarTooLarge($table, $column, $maxScalarBytes);
            }

            if (! mb_check_encoding($value, 'UTF-8')) {
                $row[$column] = ['__b64' => base64_encode($value)];
            }
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function sourceProvenance(): array
    {
        $connection = DB::connection();

        try {
            $serverVersion = (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        } catch (Throwable) {
            $serverVersion = null;
        }

        ['encoding' => $encoding, 'collation' => $collation] = $this->databaseTextMetadata($connection);

        return [
            'app_env' => (string) config('app.env'),
            'driver' => $connection->getDriverName(),
            'server_version' => $serverVersion,
            'encoding' => $encoding,
            'collation' => $collation,
        ];
    }

    /**
     * @return array{encoding: ?string, collation: ?string}
     */
    private function databaseTextMetadata(Connection $connection): array
    {
        try {
            return match ($connection->getDriverName()) {
                'mysql', 'mariadb' => $this->mysqlTextMetadata($connection),
                'pgsql' => $this->postgresTextMetadata($connection),
                'sqlite' => $this->sqliteTextMetadata($connection),
                'sqlsrv' => $this->sqlServerTextMetadata($connection),
                default => ['encoding' => null, 'collation' => null],
            };
        } catch (Throwable) {
            return ['encoding' => null, 'collation' => null];
        }
    }

    /** @return array{encoding: ?string, collation: ?string} */
    private function mysqlTextMetadata(Connection $connection): array
    {
        $row = (array) $connection->selectOne(
            'select @@character_set_database as encoding, @@collation_database as collation',
        );

        return [
            'encoding' => isset($row['encoding']) ? (string) $row['encoding'] : null,
            'collation' => isset($row['collation']) ? (string) $row['collation'] : null,
        ];
    }

    /** @return array{encoding: ?string, collation: ?string} */
    private function postgresTextMetadata(Connection $connection): array
    {
        $encoding = (array) $connection->selectOne('show server_encoding');
        $collation = (array) $connection->selectOne('show lc_collate');

        return [
            'encoding' => isset($encoding['server_encoding']) ? (string) $encoding['server_encoding'] : null,
            'collation' => isset($collation['lc_collate']) ? (string) $collation['lc_collate'] : null,
        ];
    }

    /** @return array{encoding: ?string, collation: ?string} */
    private function sqliteTextMetadata(Connection $connection): array
    {
        $row = (array) $connection->selectOne('pragma encoding');
        $encoding = reset($row);

        return [
            'encoding' => is_string($encoding) ? $encoding : null,
            // SQLite collations are declared per expression/column, not once
            // for the whole database.
            'collation' => null,
        ];
    }

    /** @return array{encoding: ?string, collation: ?string} */
    private function sqlServerTextMetadata(Connection $connection): array
    {
        $row = (array) $connection->selectOne(
            'select collation_name as collation from sys.databases where name = db_name()',
        );

        return [
            'encoding' => null,
            'collation' => isset($row['collation']) ? (string) $row['collation'] : null,
        ];
    }

    /**
     * @param  list<int|string>  $ids
     * @return list<int|string>
     */
    private function normalizeIds(array $ids): array
    {
        return array_values(array_unique($ids, SORT_REGULAR));
    }

    private function guardPackageSize(int $size): void
    {
        $maxPackageBytes = $this->settings->integer('bridge.limits.max_package_bytes', 25 * 1024 * 1024, 1, 2147483647);

        if ($size > $maxPackageBytes) {
            throw BridgeCaptureException::packageTooLarge($maxPackageBytes);
        }
    }

    private function disk(): Filesystem
    {
        $diskName = $this->settings->disk();
        $diskConfig = config("filesystems.disks.{$diskName}", []);

        if ($diskName === 'public' || ($diskConfig['visibility'] ?? null) === 'public') {
            throw BridgeCaptureException::unsafeDisk($diskName);
        }

        return $this->disks->disk($diskName);
    }
}
