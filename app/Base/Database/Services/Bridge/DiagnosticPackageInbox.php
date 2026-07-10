<?php

namespace App\Base\Database\Services\Bridge;

use App\Base\Database\Exceptions\BridgeImportException;
use App\Base\Database\Services\DevelopmentInstanceGuard;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use JsonException;

/**
 * Receives local diagnostic packages into protected Incoming storage.
 *
 * Receipt is deliberately shallow: it bounds and identifies bytes, records
 * the destination-observed hash, and does not authorize or parse domain rows.
 */
class DiagnosticPackageInbox
{
    public function __construct(
        private readonly FilesystemManager $disks,
        private readonly DevelopmentInstanceGuard $environment,
        private readonly BridgeSettings $settings,
    ) {}

    /**
     * @return array{package_id: string, package_path: string, receipt_path: string, package_sha256: string, size_bytes: int, received_at: string, transport: string, source_path: string}
     */
    public function receiveLocal(string $sourcePath): array
    {
        $this->environment->assertDevelopment(__('Diagnostic package receipt'));

        $disk = $this->disk();
        $sourcePath = trim($sourcePath, '/');
        $diagnosticsPrefix = $this->settings->pathPrefix('bridge.path_prefix', 'bridge/diagnostics');

        if (! $this->pathIsWithin($sourcePath, $diagnosticsPrefix)) {
            throw BridgeImportException::invalidSourcePath();
        }

        if (! $disk->exists($sourcePath)) {
            throw BridgeImportException::sourceMissing($sourcePath);
        }

        $maxBytes = $this->settings->integer('bridge.limits.max_package_bytes', 25 * 1024 * 1024, 1, 2147483647);
        $size = $disk->size($sourcePath);

        if ($size > $maxBytes) {
            throw BridgeImportException::packageTooLarge($maxBytes);
        }

        $contents = (string) $disk->get($sourcePath);
        $metadata = $this->shallowMetadata($contents);
        $packageId = $metadata['package_id'];
        $packageSha256 = hash('sha256', $contents);
        $directory = $this->incomingDirectory($packageId);
        $packagePath = $directory.'/package.json';
        $receiptPath = $directory.'/receipt.json';
        $wrotePackage = false;

        if ($disk->exists($packagePath)) {
            $existing = (string) $disk->get($packagePath);

            if (! hash_equals(hash('sha256', $existing), $packageSha256)) {
                throw BridgeImportException::packageIdCollision($packageId);
            }

            if ($disk->exists($receiptPath)) {
                return $this->open($packageId)['receipt'];
            }
        } else {
            if (! $disk->put($packagePath, $contents)) {
                throw BridgeImportException::receiveFailed($packagePath);
            }

            $wrotePackage = true;
        }

        $receipt = [
            'format' => 'blb-bridge-receipt',
            'format_version' => 1,
            'package_id' => $packageId,
            'package_path' => $packagePath,
            'receipt_path' => $receiptPath,
            'package_sha256' => $packageSha256,
            'size_bytes' => strlen($contents),
            'received_at' => now()->toIso8601String(),
            'transport' => 'local',
            'source_path' => $sourcePath,
        ];

        if (! $disk->put($receiptPath, CanonicalJson::encode($receipt))) {
            if ($wrotePackage) {
                $disk->delete($packagePath);
            }

            throw BridgeImportException::receiveFailed($receiptPath);
        }

        return $receipt;
    }

    /**
     * @return array{receipt: array{package_id: string, package_path: string, receipt_path: string, package_sha256: string, size_bytes: int, received_at: string, transport: string, source_path: string}, contents: string}
     */
    public function open(string $packageId): array
    {
        $this->environment->assertDevelopment(__('Diagnostic package import'));
        $this->guardPackageId($packageId);

        $disk = $this->disk();
        $directory = $this->incomingDirectory($packageId);
        $packagePath = $directory.'/package.json';
        $receiptPath = $directory.'/receipt.json';

        if (! $disk->exists($packagePath) || ! $disk->exists($receiptPath)) {
            throw BridgeImportException::incomingMissing($packageId);
        }

        $maxBytes = $this->settings->integer('bridge.limits.max_package_bytes', 25 * 1024 * 1024, 1, 2147483647);
        if ($disk->size($receiptPath) > 64 * 1024) {
            throw BridgeImportException::receiptMismatch($packageId);
        }

        $size = $disk->size($packagePath);

        if ($size > $maxBytes) {
            throw BridgeImportException::packageTooLarge($maxBytes);
        }

        $contents = (string) $disk->get($packagePath);

        try {
            $receipt = json_decode((string) $disk->get($receiptPath), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw BridgeImportException::receiptMismatch($packageId);
        }

        if (! is_array($receipt)
            || ($receipt['package_id'] ?? null) !== $packageId
            || ($receipt['package_path'] ?? null) !== $packagePath
            || ($receipt['receipt_path'] ?? null) !== $receiptPath
            || ! is_string($receipt['package_sha256'] ?? null)
            || ! hash_equals($receipt['package_sha256'], hash('sha256', $contents))
            || ($receipt['size_bytes'] ?? null) !== strlen($contents)) {
            throw BridgeImportException::receiptMismatch($packageId);
        }

        return ['receipt' => $receipt, 'contents' => $contents];
    }

    /**
     * @return array{package_id: string}
     */
    private function shallowMetadata(string $contents): array
    {
        try {
            $package = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw BridgeImportException::invalidPackage(__('JSON cannot be decoded.'));
        }

        if (! is_array($package)
            || ($package['format'] ?? null) !== DiagnosticRowCapture::FORMAT
            || ($package['format_version'] ?? null) !== DiagnosticRowCapture::FORMAT_VERSION
            || ($package['marker'] ?? null) !== 'diagnostic'
            || ($package['import_policy'] ?? null) !== 'development-only'
            || ! is_string($package['package_id'] ?? null)) {
            throw BridgeImportException::invalidPackage(__('the diagnostic marker or format is missing.'));
        }

        $this->guardPackageId($package['package_id']);

        return ['package_id' => $package['package_id']];
    }

    private function incomingDirectory(string $packageId): string
    {
        return $this->settings->pathPrefix('bridge.incoming_path_prefix', 'bridge/incoming')
            .'/diagnostic/'.$packageId;
    }

    private function guardPackageId(string $packageId): void
    {
        if (preg_match('/\Adiag-[0-9a-hjkmnp-tv-z]{26}\z/', $packageId) !== 1) {
            throw BridgeImportException::invalidPackage(__('the package identifier is malformed.'));
        }
    }

    private function pathIsWithin(string $path, string $prefix): bool
    {
        return $path !== ''
            && ! str_contains($path, '..')
            && ! str_contains($path, '\\')
            && str_starts_with($path, $prefix.'/');
    }

    private function disk(): Filesystem
    {
        $diskName = $this->settings->disk();
        $diskConfig = config("filesystems.disks.{$diskName}", []);

        if ($diskName === 'public' || ($diskConfig['visibility'] ?? null) === 'public') {
            throw BridgeImportException::unsafeDisk($diskName);
        }

        return $this->disks->disk($diskName);
    }
}
