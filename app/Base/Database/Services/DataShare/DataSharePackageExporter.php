<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\DTO\DataShare\DataShareExportPreview;
use App\Base\Database\DTO\DataShare\DataShareExportResult;
use App\Base\Database\DTO\DataShare\DataShareScopeDefinition;
use App\Base\Database\DTO\DataShare\SerializedDataShareScope;
use App\Base\Database\Exceptions\DataSharePackageException;
use App\Base\Database\Exceptions\DataSharePackageStorageException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class DataSharePackageExporter
{
    public const FORMAT = 'belimbing-data-share/package/v1';

    public function __construct(
        private readonly DataShareScopeCatalog $catalog,
        private readonly RelationalDataSerializer $serializer,
        private readonly DataShareInstanceIdentityResolver $instances,
        private readonly DataSharePrivateStorage $storage,
        private readonly DataShareSettings $settings,
    ) {}

    /** @param list<string> $tables */
    public function preview(string $scopeName, array $tables): DataShareExportPreview
    {
        [$scope, $serialized] = $this->serializeScope($scopeName, $tables);

        try {
            $report = $this->report($scope, $serialized);
            $previewHash = hash('sha256', CanonicalJson::encode($report));
            $estimatedBytes = strlen(CanonicalJson::encode($report))
                + array_sum(array_column(array_map(fn ($payload): array => $payload->metadata, $serialized->payloads), 'bytes'))
                + 128;
            $this->guardPackageSize($estimatedBytes);

            return new DataShareExportPreview($previewHash, $estimatedBytes, $report);
        } finally {
            $serialized->cleanup();
        }
    }

    /** @param list<string> $tables */
    public function export(
        string $scopeName,
        array $tables,
        string $offerId,
        string $expiresAt,
        string $expectedPreviewHash,
    ): DataShareExportResult {
        [$scope, $serialized] = $this->serializeScope($scopeName, $tables);
        $temporaryPackage = null;

        try {
            $report = $this->report($scope, $serialized);
            $previewHash = hash('sha256', CanonicalJson::encode($report));

            if (! hash_equals($expectedPreviewHash, $previewHash)) {
                throw DataSharePackageException::previewChanged();
            }

            $now = CarbonImmutable::now('UTC');
            $packageId = (string) Str::lower((string) Str::ulid());
            $manifest = [
                ...$report,
                'transfer_offer_id' => $offerId,
                'package_id' => $packageId,
                'created_at' => $now->toIso8601String(),
                'expires_at' => CarbonImmutable::parse($expiresAt, 'UTC')->toIso8601String(),
                'preview_sha256' => $previewHash,
            ];
            $temporaryPackage = $this->writePackage($manifest, $serialized);
            $bytes = filesize($temporaryPackage);
            $sha256 = hash_file('sha256', $temporaryPackage);

            if ($bytes === false || $sha256 === false) {
                throw DataSharePackageStorageException::payloadInspectionFailed();
            }

            $this->guardPackageSize($bytes);
            $path = $this->storage->outgoingPath($packageId);
            $disk = $this->storage->disk();
            $stream = fopen($temporaryPackage, 'rb');

            if ($stream === false || ! $disk->put($path, $stream)) {
                if (is_resource($stream)) {
                    fclose($stream);
                }

                throw DataSharePackageException::storeFailed($path);
            }

            fclose($stream);

            if ($disk->size($path) !== $bytes || ! hash_equals($sha256, $this->hashStoredFile($path))) {
                $disk->delete($path);
                throw DataSharePackageException::storeFailed($path);
            }

            return new DataShareExportResult($packageId, $path, $sha256, $bytes, $manifest);
        } finally {
            $serialized->cleanup();

            if ($temporaryPackage !== null) {
                @unlink($temporaryPackage);
            }
        }
    }

    /**
     * @param  list<string>  $tables
     * @return array{0: DataShareScopeDefinition, 1: SerializedDataShareScope}
     */
    private function serializeScope(string $scopeName, array $tables): array
    {
        $scope = $this->catalog->scope($scopeName, $tables);
        $maxTables = $this->settings->integer('data_share.transfer_limits.max_tables', 250, 1, 10000);

        if (count($scope->tables) > $maxTables) {
            throw DataSharePackageException::tooManyTables($maxTables);
        }

        $serialized = DB::transaction(fn (): SerializedDataShareScope => $this->serializer->serialize($scope));

        return [$scope, $serialized];
    }

    /** @return array<string, mixed> */
    private function report(
        DataShareScopeDefinition $scope,
        SerializedDataShareScope $serialized,
    ): array {
        return [
            'format' => self::FORMAT,
            'scope' => [
                'name' => $scope->name,
                'label' => $scope->label,
                'module_path' => $scope->modulePath,
                'tables' => array_column($scope->tables, 'table'),
            ],
            'source' => [
                ...$this->instances->current()->toArray(),
                'database_driver' => DB::connection()->getDriverName(),
            ],
            'counts' => $serialized->counts,
            'payloads' => array_map(fn ($payload): array => $payload->metadata, $serialized->payloads),
            'conflict_policy' => 'reject',
        ];
    }

    /** @param array<string, mixed> $manifest */
    private function writePackage(array $manifest, SerializedDataShareScope $serialized): string
    {
        $path = tempnam(sys_get_temp_dir(), 'blb-data-share-package-');

        if ($path === false) {
            throw DataSharePackageStorageException::temporaryStorageUnavailable();
        }

        @chmod($path, 0600);
        $output = fopen($path, 'wb');

        if ($output === false) {
            @unlink($path);
            throw DataSharePackageStorageException::temporaryStorageOpenFailed();
        }

        try {
            fwrite($output, CanonicalJson::encode([
                'format' => self::FORMAT,
                'manifest' => $manifest,
            ])."\n");

            foreach ($serialized->payloads as $payload) {
                $input = fopen($payload->path, 'rb');

                if ($input === false) {
                    throw DataSharePackageStorageException::payloadReopenFailed();
                }

                try {
                    stream_copy_to_stream($input, $output);
                } finally {
                    fclose($input);
                }
            }
        } catch (Throwable $e) {
            fclose($output);
            @unlink($path);
            throw $e;
        }

        fclose($output);

        return $path;
    }

    private function hashStoredFile(string $path): string
    {
        $stream = $this->storage->disk()->readStream($path);

        if ($stream === false) {
            throw DataSharePackageException::storeFailed($path);
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    private function guardPackageSize(int $bytes): void
    {
        $max = $this->settings->integer('data_share.transfer_limits.max_package_bytes', 250 * 1024 * 1024, 1, 2147483647);

        if ($bytes > $max) {
            throw DataSharePackageException::packageTooLarge($max);
        }
    }
}
