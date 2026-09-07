<?php

namespace App\Base\Database\Services\Backup;

use App\Base\Database\Exceptions\BackupException;
use App\Base\Database\Services\Backup\Encryption\AppKeyEncryption;
use App\Base\Database\Services\Backup\Encryption\EncryptionMode;
use App\Base\Database\Services\Backup\Encryption\EncryptionModeRegistry;
use App\Base\Database\Services\Backup\Writers\BackupWriter;
use App\Base\Database\Services\Backup\Writers\PostgresWriter;
use App\Base\Database\Services\Backup\Writers\SqliteWriter;
use App\Base\Foundation\Contracts\SemanticActionRecorder;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * Orchestrates the backup pipeline:
 *
 *   1. Resolve a driver-specific writer for the configured DB connection.
 *   2. Produce a plaintext dump in a secure local temp file.
 *   3. Apply the configured encryption mode to produce the artifact.
 *   4. Upload the artifact and a sidecar manifest to the chosen disk.
 *   5. Clean up local temp regardless of outcome.
 *
 * Operator actions that create, verify, delete, or prune artifacts also write
 * one SemanticActionRecorder row each (#897), so recovery material cannot
 * appear or disappear without a trail on Operator Activity.
 */
final class BackupService
{
    private const MANIFEST_SUFFIX = '.manifest.json';

    public const EVENT_CREATED = 'database.backup.created';

    public const EVENT_VERIFIED = 'database.backup.verified';

    public const EVENT_VERIFY_FAILED = 'database.backup.verify_failed';

    public const EVENT_DELETED = 'database.backup.deleted';

    public const EVENT_PRUNED = 'database.backup.pruned';

    public const SURFACE_ADMIN = 'admin.system.database.backups';

    public const SURFACE_CONSOLE = 'console:blb:db:backup';

    public function __construct(
        private readonly DatabaseManager $databaseManager,
        private readonly FilesystemManager $filesystemManager,
        private readonly EncryptionModeRegistry $encryptionModes,
        private readonly SemanticActionRecorder $semanticActions,
    ) {}

    /**
     * @param  array<string, mixed>  $config  Resolved config('backup') array.
     */
    public function backup(
        array $config,
        string $diskName,
        string $trigger,
        bool $dryRun,
        ?string $surface = null,
        ?string $uiElement = null,
    ): BackupResult {
        $writer = $this->resolveWriter($config);
        $encryption = $this->resolveEncryption($config);
        $disk = $this->filesystemManager->disk($diskName);

        // Always validate before doing any work.
        $writer->ensureToolingAvailable();
        $encryption->ensureReady();
        if ($this->findFingerprintMismatches($config, $diskName) !== []) {
            throw BackupException::configurationInvalid('APP_KEY does not match one or more existing backup manifests; re-key them before creating another backup');
        }
        $this->ensureDiskWritable($disk, $config, $diskName);

        if ($dryRun) {
            return new BackupResult(
                manifest: null,
                artifactPath: null,
                manifestPath: null,
                dryRun: true,
                driver: $writer->driver(),
                encryptionMode: $encryption->name(),
            );
        }

        $backupId = (string) Str::lower((string) Str::ulid());
        $startedAt = Carbon::now('UTC');
        $environment = (string) config('app.env', 'production');
        $prefix = trim((string) ($config['path_prefix'] ?? 'backups'), '/');
        $directory = $prefix === '' ? $environment : "{$prefix}/{$environment}";

        $timestamp = $startedAt->format('Ymd-His');
        $artifactBaseName = "{$timestamp}-{$backupId}.bak";
        $artifactName = $artifactBaseName.$encryption->extension();
        $manifestName = "{$timestamp}-{$backupId}".self::MANIFEST_SUFFIX;

        $artifactPath = "{$directory}/{$artifactName}";
        $manifestPath = "{$directory}/{$manifestName}";

        $tmpDump = $this->makeSecureTempFile('blb-dump-');
        $tmpArtifact = $this->makeSecureTempFile('blb-artifact-');

        // makeSecureTempFile creates the file; encryption insists the
        // destination doesn't exist yet, so clear it.
        @unlink($tmpArtifact);

        try {
            $writer->dump($tmpDump);
            $encryptResult = $encryption->encryptFile($tmpDump, $tmpArtifact);

            $sizeBytes = (int) @filesize($tmpArtifact);
            $sha256 = (string) hash_file('sha256', $tmpArtifact);

            $this->putFile($disk, $artifactPath, $tmpArtifact, $diskName);

            $finishedAt = Carbon::now('UTC');

            $manifest = new Manifest(
                backupId: $backupId,
                driver: $writer->driver(),
                encryptionMode: $encryption->name(),
                sourceLabel: $writer->sourceLabel(),
                engineVersion: $writer->engineVersion(),
                appEnvironment: $environment,
                artifactPath: $artifactPath,
                sizeBytes: $sizeBytes,
                sha256: $sha256,
                startedAt: $startedAt->toIso8601String(),
                finishedAt: $finishedAt->toIso8601String(),
                trigger: $trigger,
                status: 'success',
                wrappedDek: $encryptResult->wrappedDek,
                dekNonce: $encryptResult->dekNonce,
                kekFingerprint: $encryptResult->kekFingerprint,
            );

            $disk->put($manifestPath, (string) json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            $this->record(
                event: self::EVENT_CREATED,
                summary: __('Created database backup :id', ['id' => $backupId]),
                manifestPath: $manifestPath,
                sha256: $sha256,
                surface: $surface,
                uiElement: $uiElement,
                context: [
                    'backup_id' => $backupId,
                    'artifact_path' => $artifactPath,
                    'size_bytes' => $sizeBytes,
                    'encryption_mode' => $encryption->name(),
                    'disk' => $diskName,
                    'trigger' => $trigger,
                ],
            );

            return new BackupResult(
                manifest: $manifest,
                artifactPath: $artifactPath,
                manifestPath: $manifestPath,
                dryRun: false,
                driver: $writer->driver(),
                encryptionMode: $encryption->name(),
            );
        } catch (Throwable $e) {
            // Best-effort cleanup of any remote artifact written before failure.
            try {
                if ($disk->exists($artifactPath)) {
                    $disk->delete($artifactPath);
                }
            } catch (Throwable) {
                // ignore secondary cleanup error
            }

            throw $e;
        } finally {
            @unlink($tmpDump);
            @unlink($tmpArtifact);
        }
    }

    /**
     * Compare the artifact's SHA-256 to the manifest and record verified or verify_failed.
     *
     * @return bool True when the hash matches a non-empty expected digest.
     */
    public function verifyIntegrity(
        Filesystem $disk,
        string $manifestPath,
        ?string $surface = null,
        ?string $uiElement = null,
    ): bool {
        if (! $disk->exists($manifestPath)) {
            throw BackupException::artifactCorrupt("Manifest not found: {$manifestPath}");
        }

        $manifestData = json_decode((string) $disk->get($manifestPath), true);
        if (! is_array($manifestData)) {
            throw BackupException::artifactCorrupt('Manifest is not valid JSON.');
        }

        $artifactPath = (string) ($manifestData['artifact_path'] ?? '');
        $expectedHash = (string) ($manifestData['sha256'] ?? '');
        $backupId = (string) ($manifestData['backup_id'] ?? '');

        if ($artifactPath === '' || ! $disk->exists($artifactPath)) {
            throw BackupException::artifactNotFound($artifactPath !== '' ? $artifactPath : $backupId);
        }

        $actualHash = $this->hashRemoteArtifact($disk, $artifactPath);
        $ok = $actualHash === $expectedHash && $expectedHash !== '';

        $this->record(
            event: $ok ? self::EVENT_VERIFIED : self::EVENT_VERIFY_FAILED,
            summary: $ok
                ? __('Verified database backup :id', ['id' => $backupId !== '' ? $backupId : $manifestPath])
                : __('Verify failed for database backup :id', ['id' => $backupId !== '' ? $backupId : $manifestPath]),
            manifestPath: $manifestPath,
            sha256: $expectedHash,
            surface: $surface,
            uiElement: $uiElement,
            context: [
                'backup_id' => $backupId,
                'artifact_path' => $artifactPath,
                'size_bytes' => (int) ($manifestData['size_bytes'] ?? 0),
                'encryption_mode' => (string) ($manifestData['encryption_mode'] ?? ''),
            ],
            result: $ok ? 'succeeded' : 'failed',
        );

        return $ok;
    }

    /**
     * Delete the artifact and its manifest, then record database.backup.deleted.
     */
    public function deleteBackup(
        string $diskName,
        string $manifestPath,
        ?string $surface = null,
        ?string $uiElement = null,
    ): void {
        $disk = $this->filesystemManager->disk($diskName);

        if (! $disk->exists($manifestPath)) {
            return;
        }

        $manifestData = json_decode((string) $disk->get($manifestPath), true);
        $artifactPath = is_array($manifestData) ? (string) ($manifestData['artifact_path'] ?? '') : '';
        $backupId = is_array($manifestData) ? (string) ($manifestData['backup_id'] ?? '') : '';
        $sha256 = is_array($manifestData) ? (string) ($manifestData['sha256'] ?? '') : '';
        $sizeBytes = is_array($manifestData) ? (int) ($manifestData['size_bytes'] ?? 0) : 0;
        $encryptionMode = is_array($manifestData) ? (string) ($manifestData['encryption_mode'] ?? '') : '';

        if ($artifactPath !== '' && $disk->exists($artifactPath)) {
            $disk->delete($artifactPath);
        }
        $disk->delete($manifestPath);

        $this->record(
            event: self::EVENT_DELETED,
            summary: __('Deleted database backup :id', ['id' => $backupId !== '' ? $backupId : $manifestPath]),
            manifestPath: $manifestPath,
            sha256: $sha256,
            surface: $surface,
            uiElement: $uiElement,
            context: [
                'backup_id' => $backupId,
                'artifact_path' => $artifactPath,
                'manifest_path' => $manifestPath,
                'size_bytes' => $sizeBytes,
                'encryption_mode' => $encryptionMode,
                'disk' => $diskName,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function resolveWriter(array $config): BackupWriter
    {
        $connectionName = $config['connection'] ?? null;
        $connection = $connectionName === null
            ? $this->databaseManager->connection()
            : $this->databaseManager->connection((string) $connectionName);
        $driver = $connection->getDriverName();

        return match ($driver) {
            'pgsql' => new PostgresWriter($connection),
            'sqlite' => new SqliteWriter($connection),
            default => throw BackupException::driverUnsupported($driver),
        };
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function resolveEncryption(array $config): EncryptionMode
    {
        $mode = (string) ($config['encryption']['mode'] ?? 'app-key');

        return $this->encryptionModes->resolve($mode, $config);
    }

    /**
     * Check whether any app-key manifests on the disk have a kek_fingerprint that
     * does not match the current APP_KEY. Returns a list of backup IDs that cannot
     * be decrypted with the current key.
     *
     * Returns an empty array when the configured mode is not 'app-key', when
     * APP_KEY is absent/invalid (ensureReady() will catch that separately), or
     * when all manifests are already on the current key.
     *
     * @param  array<string, mixed>  $config
     * @return list<string> Backup IDs with mismatched fingerprints.
     */
    public function findFingerprintMismatches(array $config, string $diskName): array
    {
        $mode = (string) ($config['encryption']['mode'] ?? 'app-key');
        if ($mode !== 'app-key') {
            return [];
        }

        $currentFingerprint = AppKeyEncryption::currentFingerprint();
        if ($currentFingerprint === null) {
            return []; // Invalid APP_KEY — ensureReady() will report this.
        }

        $disk = $this->filesystemManager->disk($diskName);
        $prefix = trim((string) ($config['path_prefix'] ?? 'backups'), '/');
        $environment = (string) config('app.env', 'production');
        $directory = $prefix === '' ? $environment : "{$prefix}/{$environment}";

        $mismatches = [];

        foreach ($disk->files($directory) as $file) {
            if (! str_ends_with($file, self::MANIFEST_SUFFIX)) {
                continue;
            }

            $data = json_decode((string) $disk->get($file), true);
            if (! is_array($data) || ($data['encryption_mode'] ?? '') !== 'app-key') {
                continue;
            }

            $manifestFingerprint = (string) ($data['kek_fingerprint'] ?? '');
            if ($manifestFingerprint !== '' && $manifestFingerprint !== $currentFingerprint) {
                $mismatches[] = (string) ($data['backup_id'] ?? $file);
            }
        }

        return $mismatches;
    }

    private function ensureDiskWritable(Filesystem $disk, array $config, string $diskName): void
    {
        $prefix = trim((string) ($config['path_prefix'] ?? 'backups'), '/');
        $environment = (string) config('app.env', 'production');
        $directory = $prefix === '' ? $environment : "{$prefix}/{$environment}";
        $probe = $directory.'/.blb-write-probe-'.bin2hex(random_bytes(4));

        try {
            $disk->put($probe, 'probe');
            if (! $disk->exists($probe)) {
                throw BackupException::storageFailed("Disk '{$diskName}' wrote successfully but probe is not visible");
            }
            $disk->delete($probe);
        } catch (BackupException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw BackupException::storageFailed("Disk '{$diskName}' is not writable: ".$e->getMessage(), $e);
        }
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public function findManifest(Filesystem $disk, array $config, string $backupId): Manifest
    {
        $prefix = trim((string) ($config['path_prefix'] ?? 'backups'), '/');
        $environment = (string) config('app.env', 'production');
        $directory = $prefix === '' ? $environment : "{$prefix}/{$environment}";

        $files = $disk->files($directory);
        foreach ($files as $file) {
            if (str_ends_with($file, self::MANIFEST_SUFFIX) && str_contains($file, $backupId)) {
                $contents = (string) $disk->get($file);
                $data = json_decode($contents, true);
                if (! is_array($data)) {
                    throw BackupException::artifactCorrupt("Manifest is not valid JSON: {$file}");
                }

                return Manifest::fromArray($data);
            }
        }

        throw BackupException::artifactNotFound($backupId);
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<int, array{manifest_path: string, artifact_path: string, finished_at_unix: int}>
     */
    public function listManifestEntries(array $config, string $diskName): array
    {
        $disk = $this->filesystemManager->disk($diskName);
        $prefix = trim((string) ($config['path_prefix'] ?? 'backups'), '/');
        $environment = (string) config('app.env', 'production');
        $directory = $prefix === '' ? $environment : "{$prefix}/{$environment}";

        $files = $disk->files($directory);
        $entries = [];

        foreach ($files as $file) {
            if (! str_ends_with($file, self::MANIFEST_SUFFIX)) {
                continue;
            }

            $contents = (string) $disk->get($file);
            $data = json_decode($contents, true);
            if (! is_array($data)) {
                continue;
            }
            $manifest = Manifest::fromArray($data);
            $finishedUnix = $manifest->finishedAt !== ''
                ? (int) Carbon::parse($manifest->finishedAt)->timestamp
                : 0;

            $entries[] = [
                'manifest_path' => $file,
                'artifact_path' => $manifest->artifactPath,
                'finished_at_unix' => $finishedUnix,
            ];
        }

        usort($entries, fn ($a, $b) => $b['finished_at_unix'] <=> $a['finished_at_unix']);

        return $entries;
    }

    /**
     * @param  array<int, array{manifest_path: string, artifact_path: string, finished_at_unix: int}>  $entries
     * @return array<int, string> Paths of removed objects.
     */
    public function deleteEntries(
        string $diskName,
        array $entries,
        ?string $surface = null,
        ?string $uiElement = null,
        int $keepDays = 0,
    ): array {
        $disk = $this->filesystemManager->disk($diskName);
        $removed = [];

        foreach ($entries as $entry) {
            $manifestPath = (string) ($entry['manifest_path'] ?? '');
            $manifestData = ($manifestPath !== '' && $disk->exists($manifestPath))
                ? json_decode((string) $disk->get($manifestPath), true)
                : null;
            $backupId = is_array($manifestData) ? (string) ($manifestData['backup_id'] ?? '') : '';
            $sha256 = is_array($manifestData) ? (string) ($manifestData['sha256'] ?? '') : '';
            $sizeBytes = is_array($manifestData) ? (int) ($manifestData['size_bytes'] ?? 0) : 0;
            $encryptionMode = is_array($manifestData) ? (string) ($manifestData['encryption_mode'] ?? '') : '';

            foreach ([$entry['artifact_path'], $entry['manifest_path']] as $path) {
                if ($path !== '' && $disk->exists($path)) {
                    $disk->delete($path);
                    $removed[] = $path;
                }
            }

            $this->record(
                event: self::EVENT_PRUNED,
                summary: __('Pruned expired database backup :id', ['id' => $backupId !== '' ? $backupId : $manifestPath]),
                manifestPath: $manifestPath,
                sha256: $sha256,
                surface: $surface ?? self::SURFACE_CONSOLE,
                uiElement: $uiElement ?? '--prune',
                context: [
                    'backup_id' => $backupId,
                    'artifact_path' => (string) ($entry['artifact_path'] ?? ''),
                    'manifest_path' => $manifestPath,
                    'size_bytes' => $sizeBytes,
                    'encryption_mode' => $encryptionMode,
                    'disk' => $diskName,
                    'keep_days' => $keepDays,
                    'pruned_count' => 1,
                ],
            );
        }

        return $removed;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(
        string $event,
        string $summary,
        string $manifestPath,
        string $sha256,
        ?string $surface,
        ?string $uiElement,
        array $context,
        string $result = 'succeeded',
    ): void {
        $id = $sha256 !== '' ? substr($sha256, 0, 12) : ($context['backup_id'] ?? $manifestPath);

        $this->semanticActions->record(
            event: $event,
            summary: $summary,
            source: __('Database Backups'),
            subject: [
                'name' => 'backup',
                'id' => (string) $id,
                'identifier' => $manifestPath !== '' ? $manifestPath : null,
            ],
            surface: $surface,
            uiElement: $uiElement,
            context: $context,
            result: $result,
        );
    }

    private function hashRemoteArtifact(Filesystem $disk, string $path): string
    {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            return '';
        }

        $ctx = hash_init('sha256');
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                hash_update($ctx, $chunk);
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return hash_final($ctx);
    }

    private function makeSecureTempFile(string $prefix): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw BackupException::dumpFailed('Could not allocate a secure temporary file');
        }
        @chmod($path, 0600);

        return $path;
    }

    private function putFile(Filesystem $disk, string $path, string $localPath, string $diskName): void
    {
        $stream = fopen($localPath, 'rb');
        if ($stream === false) {
            throw BackupException::storageFailed("Could not read local artifact at {$localPath}");
        }

        try {
            $ok = $disk->writeStream($path, $stream);
            if ($ok === false) {
                throw BackupException::storageFailed("Disk '{$diskName}' refused to write {$path}");
            }
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
