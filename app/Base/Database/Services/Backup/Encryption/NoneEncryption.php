<?php
namespace App\Base\Database\Services\Backup\Encryption;

use App\Base\Database\Exceptions\BackupException;
use App\Base\Database\Services\Backup\Manifest;

/**
 * Pass-through encryption mode. The plaintext dump becomes the artifact.
 *
 * Acceptable for small single-tenant deployments with no sensitive data, where
 * the storage disk's access controls are the security boundary. The backup
 * command warns explicitly when this mode is selected so it is never silent.
 */
final class NoneEncryption implements EncryptionMode
{
    public function name(): string
    {
        return 'none';
    }

    public function extension(): string
    {
        return '';
    }

    public function ensureReady(): void
    {
        // No key material required.
    }

    public function encryptFile(string $sourcePath, string $destinationPath): EncryptResult
    {
        if (! is_file($sourcePath)) {
            throw BackupException::dumpFailed("Source dump missing: {$sourcePath}");
        }

        if (file_exists($destinationPath)) {
            throw BackupException::storageFailed("Destination already exists: {$destinationPath}");
        }

        if (! @rename($sourcePath, $destinationPath)) {
            // Cross-device rename can fail; fall back to copy + unlink.
            if (! @copy($sourcePath, $destinationPath)) {
                throw BackupException::storageFailed("Could not move dump to {$destinationPath}");
            }
            @unlink($sourcePath);
        }

        @chmod($destinationPath, 0600);

        return new EncryptResult();
    }

    public function decryptFile(string $sourcePath, string $destinationPath, ?Manifest $manifest = null): void
    {
        if (! is_file($sourcePath)) {
            throw BackupException::artifactNotFound($sourcePath);
        }

        if (file_exists($destinationPath)) {
            throw BackupException::restoreFailed("Destination already exists: {$destinationPath}");
        }

        if (! @copy($sourcePath, $destinationPath)) {
            throw BackupException::restoreFailed("Could not stage artifact at {$destinationPath}");
        }

        @chmod($destinationPath, 0600);
    }
}
