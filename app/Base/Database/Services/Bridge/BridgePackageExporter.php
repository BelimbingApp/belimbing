<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\DTO\Bridge\BridgeExportPreview;
use App\Base\Database\DTO\Bridge\BridgeExportResult;
use App\Base\Database\DTO\Bridge\BridgeInstanceIdentity;
use App\Base\Database\DTO\Bridge\BridgeReceiveGrantBundle;
use App\Base\Database\DTO\Bridge\BridgeScopeDefinition;
use App\Base\Database\DTO\Bridge\SerializedBridgeScope;
use App\Base\Database\Exceptions\BridgePackageException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class BridgePackageExporter
{
    public const FORMAT = 'belimbing-data-bridge/v2';

    public function __construct(
        private readonly BridgeScopeCatalog $catalog,
        private readonly RelationalDataSerializer $serializer,
        private readonly BridgeInstanceIdentityResolver $instances,
        private readonly BridgeDirectionPolicy $directions,
        private readonly BridgePrivateStorage $storage,
        private readonly BridgeEventRecorder $events,
        private readonly BridgeSettings $settings,
    ) {}

    /** @param list<string> $tables */
    public function preview(string $scopeName, array $tables, BridgeReceiveGrantBundle $grant): BridgeExportPreview
    {
        [$scope, $serialized] = $this->serializeScope($scopeName, $tables, $grant);

        try {
            $report = $this->report($scope, $grant, $serialized);
            $previewHash = hash('sha256', CanonicalJson::encode($report));
            $estimatedBytes = strlen(CanonicalJson::encode($report))
                + array_sum(array_column(array_map(fn ($payload): array => $payload->metadata, $serialized->payloads), 'bytes'))
                + 128;
            $this->guardPackageSize($estimatedBytes, $grant->maxBytes);

            return new BridgeExportPreview($previewHash, $estimatedBytes, $report);
        } finally {
            $serialized->cleanup();
        }
    }

    /** @param list<string> $tables */
    public function export(
        string $scopeName,
        array $tables,
        BridgeReceiveGrantBundle $grant,
        string $expectedPreviewHash,
    ): BridgeExportResult {
        [$scope, $serialized] = $this->serializeScope($scopeName, $tables, $grant);
        $temporaryPackage = null;

        try {
            $report = $this->report($scope, $grant, $serialized);
            $previewHash = hash('sha256', CanonicalJson::encode($report));

            if (! hash_equals($expectedPreviewHash, $previewHash)) {
                throw BridgePackageException::previewChanged();
            }

            $now = CarbonImmutable::now('UTC');
            $packageId = (string) Str::lower((string) Str::ulid());
            $manifest = [
                ...$report,
                'package_id' => $packageId,
                'created_at' => $now->toIso8601String(),
                'expires_at' => $now->addHours($this->settings->integer('bridge.transfer_limits.expiry_hours', 24, 1, 168))->toIso8601String(),
                'preview_sha256' => $previewHash,
            ];
            $temporaryPackage = $this->writePackage($manifest, $serialized);
            $bytes = filesize($temporaryPackage);
            $sha256 = hash_file('sha256', $temporaryPackage);

            if ($bytes === false || $sha256 === false) {
                throw BridgePackageException::payloadInspectionFailed();
            }

            $this->guardPackageSize($bytes, $grant->maxBytes);
            $path = $this->storage->outgoingPath($packageId);
            $disk = $this->storage->disk();
            $stream = fopen($temporaryPackage, 'rb');

            if ($stream === false || ! $disk->put($path, $stream)) {
                if (is_resource($stream)) {
                    fclose($stream);
                }

                throw BridgePackageException::storeFailed($path);
            }

            fclose($stream);

            if ($disk->size($path) !== $bytes || ! hash_equals($sha256, $this->hashStoredFile($path))) {
                $disk->delete($path);
                throw BridgePackageException::storeFailed($path);
            }

            $this->events->recordExport('exported', $manifest, [
                'bytes' => $bytes,
                'counts' => $manifest['counts'],
                'package_sha256' => $sha256,
                'tables' => $manifest['scope']['tables'],
            ]);

            return new BridgeExportResult($packageId, $path, $sha256, $bytes, $manifest);
        } finally {
            $serialized->cleanup();

            if ($temporaryPackage !== null) {
                @unlink($temporaryPackage);
            }
        }
    }

    /**
     * @param  list<string>  $tables
     * @return array{0: BridgeScopeDefinition, 1: SerializedBridgeScope}
     */
    private function serializeScope(string $scopeName, array $tables, BridgeReceiveGrantBundle $grant): array
    {
        $source = $this->instances->current();
        $this->assertGrantMatches($source, $scopeName, $grant);
        $scope = $this->catalog->scope($scopeName, $tables);
        $this->directions->assertAllowed($source, $grant->target);
        $maxTables = $this->settings->integer('bridge.transfer_limits.max_tables', 250, 1, 10000);

        if (count($scope->tables) > $maxTables) {
            throw BridgePackageException::tooManyTables($maxTables);
        }

        $serialized = DB::transaction(fn (): SerializedBridgeScope => $this->serializer->serialize($scope));

        return [$scope, $serialized];
    }

    /** @return array<string, mixed> */
    private function report(
        BridgeScopeDefinition $scope,
        BridgeReceiveGrantBundle $grant,
        SerializedBridgeScope $serialized,
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
            'target' => $grant->target->toArray(),
            'receive_grant_id' => $grant->grantId,
            'counts' => $serialized->counts,
            'payloads' => array_map(fn ($payload): array => $payload->metadata, $serialized->payloads),
            'conflict_policy' => 'reject',
        ];
    }

    private function assertGrantMatches(
        BridgeInstanceIdentity $source,
        string $scopeName,
        BridgeReceiveGrantBundle $grant,
    ): void {
        if ($source->id !== $grant->expectedSource->id
            || $source->role !== $grant->expectedSource->role
            || $scopeName !== $grant->scope
            || $grant->isExpired()) {
            throw BridgePackageException::invalidPackage(__('the receive key does not authorize this source, scope, or time.'));
        }
    }

    /** @param array<string, mixed> $manifest */
    private function writePackage(array $manifest, SerializedBridgeScope $serialized): string
    {
        $path = tempnam(sys_get_temp_dir(), 'blb-bridge-package-');

        if ($path === false) {
            throw BridgePackageException::temporaryStorageUnavailable();
        }

        @chmod($path, 0600);
        $output = fopen($path, 'wb');

        if ($output === false) {
            @unlink($path);
            throw BridgePackageException::temporaryStorageOpenFailed();
        }

        try {
            fwrite($output, CanonicalJson::encode([
                'format' => self::FORMAT,
                'manifest' => $manifest,
            ])."\n");

            foreach ($serialized->payloads as $payload) {
                $input = fopen($payload->path, 'rb');

                if ($input === false) {
                    throw BridgePackageException::payloadReopenFailed();
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
            throw BridgePackageException::storeFailed($path);
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);

            return hash_final($hash);
        } finally {
            fclose($stream);
        }
    }

    private function guardPackageSize(int $bytes, ?int $grantMaximum = null): void
    {
        $max = $this->settings->integer('bridge.transfer_limits.max_package_bytes', 250 * 1024 * 1024, 1, 2147483647);
        $max = $grantMaximum === null ? $max : min($max, $grantMaximum);

        if ($bytes > $max) {
            throw BridgePackageException::packageTooLarge($max);
        }
    }
}
