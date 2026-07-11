<?php

namespace App\Base\Database\Services\DataShare;

use App\Base\Database\Enums\DataShareInstanceRole;
use App\Base\Database\Exceptions\DataShareApplyException;
use App\Base\Database\Services\Backup\BackupService;
use Illuminate\Filesystem\FilesystemManager;

class DataShareRecoveryPoint
{
    public function __construct(
        private readonly DataShareInstanceIdentityResolver $instances,
        private readonly BackupService $backups,
        private readonly FilesystemManager $disks,
    ) {}

    /** @return array<string, mixed>|null */
    public function create(): ?array
    {
        if ($this->instances->current()->role !== DataShareInstanceRole::Production) {
            return null;
        }

        $config = (array) config('backup', []);

        if (($config['enabled'] ?? true) === false) {
            throw DataShareApplyException::backupRequired(__('backup.enabled is false and no provider snapshot adapter is configured.'));
        }

        $diskName = (string) ($config['disk'] ?? 'local');
        $result = $this->backups->backup($config, $diskName, 'data-share-apply', false);
        $manifest = $result->manifest;

        if ($manifest === null || $result->artifactPath === null) {
            throw DataShareApplyException::backupRequired(__('the backup service returned no artifact.'));
        }

        $disk = $this->disks->disk($diskName);
        $stream = $disk->readStream($result->artifactPath);

        if ($stream === false) {
            throw DataShareApplyException::backupRequired(__('the new backup artifact cannot be read.'));
        }

        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $actual = hash_final($hash);
        } finally {
            fclose($stream);
        }

        if (! hash_equals($manifest->sha256, $actual)) {
            throw DataShareApplyException::backupRequired(__('the new backup failed SHA-256 verification.'));
        }

        return [
            'backup_id' => $manifest->backupId,
            'artifact_path' => $result->artifactPath,
            'manifest_path' => $result->manifestPath,
            'sha256' => $manifest->sha256,
        ];
    }
}
