<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Livewire\Backups;

use App\Base\Authz\Contracts\AuthorizationService;
use App\Base\Authz\DTO\Actor;
use App\Base\Database\Exceptions\BackupException;
use App\Base\Database\Services\Backup\BackupService;
use App\Base\Database\Services\Backup\Encryption\EncryptionModeRegistry;
use App\Base\Settings\Contracts\SettingsService;
use App\Modules\Core\User\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Filesystem\FilesystemManager;
use Livewire\Component;
use Throwable;

/**
 * Admin UI for the database backup pipeline.
 *
 * Read = admin.system.database-backup.list (route middleware).
 * Write = admin.system.database-backup.create / admin.system.database-backup.delete (enforced inside actions).
 *
 * Restore is intentionally CLI-only; the UI does not expose a restore action
 * because restore writes to a database and the operator must consciously stage
 * a non-current target.
 */
class Index extends Component
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $rows = [];

    public ?string $statusMessage = null;

    public ?string $statusVariant = null;

    public function mount(BackupService $service, FilesystemManager $filesystemManager): void
    {
        $this->loadRows($service, $filesystemManager);
    }

    public function runBackup(?BackupService $service = null, ?FilesystemManager $filesystemManager = null): void
    {
        // Livewire injects services for typed parameters that aren't supplied
        // by the wire:click payload; the defaults above keep PHP's signature
        // happy when calling from a test.
        $service ??= app(BackupService::class);
        $filesystemManager ??= app(FilesystemManager::class);

        $this->requireCapability('admin.system.database-backup.create');

        $config = $this->resolveConfig();
        if (($config['enabled'] ?? true) === false) {
            $this->flash(__('Backup is disabled in configuration.'), 'warning');

            return;
        }

        $diskName = (string) ($config['disk'] ?? 'local');

        try {
            $result = $service->backup(
                config: $config,
                diskName: $diskName,
                trigger: $this->resolveTrigger(),
                dryRun: false,
            );
        } catch (BackupException $e) {
            $this->flash($e->getMessage(), 'danger');

            return;
        } catch (Throwable $e) {
            $this->flash(__('Backup failed: :reason', ['reason' => $e->getMessage()]), 'danger');

            return;
        }

        $manifest = $result->manifest;
        $this->flash(
            __(':id created (:size bytes, :mode).', [
                'id' => $manifest?->backupId ?? '',
                'size' => $manifest?->sizeBytes ?? 0,
                'mode' => $manifest?->encryptionMode ?? '',
            ]),
            'success',
        );

        $this->loadRows($service, $filesystemManager);
    }

    public function verify(string $manifestPath, BackupService $service, FilesystemManager $filesystemManager): void
    {
        $this->requireCapability('admin.system.database-backup.list');

        $config = $this->resolveConfig();
        $diskName = (string) ($config['disk'] ?? 'local');
        $disk = $filesystemManager->disk($diskName);

        if (! $disk->exists($manifestPath)) {
            $this->flash(__('Manifest not found: :path', ['path' => $manifestPath]), 'danger');

            return;
        }

        $manifestData = json_decode((string) $disk->get($manifestPath), true);
        if (! is_array($manifestData)) {
            $this->flash(__('Manifest is not valid JSON.'), 'danger');

            return;
        }

        $artifactPath = (string) ($manifestData['artifact_path'] ?? '');
        $expectedHash = (string) ($manifestData['sha256'] ?? '');

        if (! $disk->exists($artifactPath)) {
            $this->flash(__('Artifact missing: :path', ['path' => $artifactPath]), 'danger');

            return;
        }

        $actualHash = $this->hashRemoteArtifact($disk, $artifactPath);

        if ($actualHash === $expectedHash && $expectedHash !== '') {
            $this->flash(__('Integrity OK: SHA-256 matches manifest.'), 'success');
        } else {
            $this->flash(__('Integrity FAILED: artifact hash differs from manifest.'), 'danger');
        }

        $this->loadRows($service, $filesystemManager);
    }

    public function delete(string $manifestPath, BackupService $service, FilesystemManager $filesystemManager): void
    {
        $this->requireCapability('admin.system.database-backup.delete');

        $config = $this->resolveConfig();
        $diskName = (string) ($config['disk'] ?? 'local');
        $disk = $filesystemManager->disk($diskName);

        if (! $disk->exists($manifestPath)) {
            $this->flash(__('Manifest already gone.'), 'warning');
            $this->loadRows($service, $filesystemManager);

            return;
        }

        $manifestData = json_decode((string) $disk->get($manifestPath), true);
        $artifactPath = is_array($manifestData) ? (string) ($manifestData['artifact_path'] ?? '') : '';

        try {
            if ($artifactPath !== '' && $disk->exists($artifactPath)) {
                $disk->delete($artifactPath);
            }
            $disk->delete($manifestPath);
        } catch (Throwable $e) {
            $this->flash(__('Delete failed: :reason', ['reason' => $e->getMessage()]), 'danger');

            return;
        }

        $this->flash(__('Backup deleted.'), 'success');
        $this->loadRows($service, $filesystemManager);
    }

    public function render(): View
    {
        $config = $this->resolveConfig();
        $mode = (string) ($config['encryption']['mode'] ?? 'app-key');

        return view('livewire.admin.system.database-backups.index', [
            'rows' => $this->rows,
            'enabled' => (bool) ($config['enabled'] ?? true),
            'mode' => $mode,
            'encryptionModes' => app(EncryptionModeRegistry::class)->modes(),
            'disk' => (string) ($config['disk'] ?? 'local'),
            'pathPrefix' => (string) ($config['path_prefix'] ?? 'backups'),
            'keepDays' => (int) ($config['retention']['keep_days'] ?? 0),
            'keepCount' => (int) ($config['retention']['keep_count'] ?? 0),
            'canCreate' => $this->capabilityAllows('admin.system.database-backup.create'),
            'canDelete' => $this->capabilityAllows('admin.system.database-backup.delete'),
            'canManageSettings' => $this->capabilityAllows('admin.system.setting.manage'),
            'statusMessage' => $this->statusMessage,
            'statusVariant' => $this->statusVariant,
        ]);
    }

    private function loadRows(BackupService $service, FilesystemManager $filesystemManager): void
    {
        $config = $this->resolveConfig();
        $diskName = (string) ($config['disk'] ?? 'local');
        $disk = $filesystemManager->disk($diskName);

        $entries = $service->listManifestEntries($config, $diskName);
        $rows = [];

        foreach ($entries as $entry) {
            $manifestPath = $entry['manifest_path'];
            $contents = (string) $disk->get($manifestPath);
            $data = json_decode($contents, true);
            if (! is_array($data)) {
                continue;
            }

            $finishedAt = (string) ($data['finished_at'] ?? '');
            $rows[] = [
                'manifest_path' => $manifestPath,
                'backup_id' => (string) ($data['backup_id'] ?? ''),
                'driver' => (string) ($data['driver'] ?? ''),
                'encryption_mode' => (string) ($data['encryption_mode'] ?? ''),
                'finished_at' => $finishedAt,
                'size_bytes' => (int) ($data['size_bytes'] ?? 0),
                'sha256_short' => substr((string) ($data['sha256'] ?? ''), 0, 12),
                'trigger' => (string) ($data['trigger'] ?? ''),
                'status' => (string) ($data['status'] ?? ''),
            ];
        }

        $this->rows = $rows;
    }

    private function hashRemoteArtifact($disk, string $path): string
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

    private function flash(string $message, string $variant): void
    {
        $this->statusMessage = $message;
        $this->statusVariant = $variant;
    }

    /**
     * Persist a single operator-editable backup setting via SettingsService.
     *
     * Called by x-ui.edit-in-place.* components on blur/change.
     * Field is validated against the declared whitelist; unknown fields are ignored.
     */
    public function saveField(string $field, mixed $value): void
    {
        $this->requireCapability('admin.system.setting.manage');

        $coerced = match ($field) {
            'backup.enabled'                  => filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0',
            'backup.disk'                     => trim((string) $value),
            'backup.path_prefix'              => trim((string) $value),
            'backup.encryption.mode'          => trim((string) $value),
            'backup.retention.keep_days'      => (string) max(0, (int) $value),
            'backup.retention.keep_count'     => (string) max(0, (int) $value),
            default                           => null,
        };

        if ($coerced === null) {
            return;
        }

        app(SettingsService::class)->set($field, $coerced);
    }

    /**
     * Build the effective backup config by overlaying operator-editable settings
     * (from SettingsService) on top of the config-file baseline.
     *
     * Only the five operational knobs are overridable via settings:
     *   enabled, disk, path_prefix, retention.keep_days, retention.keep_count.
     * Encryption and connection keys remain config-file-only.
     *
     * @return array<string, mixed>
     */
    private function resolveConfig(): array
    {
        $settings = app(SettingsService::class);
        $base = (array) config('backup', []);

        return array_replace($base, [
            'enabled'     => (bool) filter_var($settings->get('backup.enabled', $base['enabled'] ?? true), FILTER_VALIDATE_BOOLEAN),
            'disk'        => (string) $settings->get('backup.disk', $base['disk'] ?? 'local'),
            'path_prefix' => (string) $settings->get('backup.path_prefix', $base['path_prefix'] ?? 'backups'),
            'encryption'  => array_replace($base['encryption'] ?? [], [
                'mode' => (string) $settings->get('backup.encryption.mode', $base['encryption']['mode'] ?? 'app-key'),
            ]),
            'retention'   => array_replace($base['retention'] ?? [], [
                'keep_days'  => (int) $settings->get('backup.retention.keep_days', $base['retention']['keep_days'] ?? 30),
                'keep_count' => (int) $settings->get('backup.retention.keep_count', $base['retention']['keep_count'] ?? 7),
            ]),
        ]);
    }

    private function resolveTrigger(): string
    {
        $user = auth()->user();
        $userId = $user instanceof User ? (int) $user->id : 0;

        return "ui:user={$userId}";
    }

    private function requireCapability(string $capability): void
    {
        if (! $this->capabilityAllows($capability)) {
            abort(403, "Capability '{$capability}' is required.");
        }
    }

    private function capabilityAllows(string $capability): bool
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return false;
        }

        return app(AuthorizationService::class)->can(Actor::forUser($user), $capability)->allowed;
    }
}
