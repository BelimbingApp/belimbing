<?php

namespace App\Base\Database\Services\Backup\Encryption;

use App\Base\Database\Services\Backup\Manifest;

/**
 * Backup encryption strategy.
 *
 * Each implementation knows its own on-disk format and is responsible for
 * transforming a plaintext file into a final artifact (encrypt) and back
 * (decrypt). Implementations must not leave additional plaintext copies on
 * disk beyond what the chosen mode strictly requires.
 *
 * Extension authors: register via EncryptionModeRegistry::register() from a
 * service provider boot() method. Factory signature: callable(array $config): EncryptionMode.
 * Use vendor-prefixed mode names (e.g. ext-acme-kms). Mode names 'none' and
 * 'app-key' are reserved for core.
 */
interface EncryptionMode
{
    /**
     * Stable identifier persisted in the manifest (e.g. 'none', 'app-key').
     * Never change this after artifacts exist in the wild.
     */
    public function name(): string;

    /**
     * File extension applied to the artifact filename for this mode
     * (e.g. '' for none, '.enc' for app-key).
     */
    public function extension(): string;

    /**
     * Verify that the mode is fully configured and any required key material
     * is available. Throws BackupException on misconfiguration.
     */
    public function ensureReady(): void;

    /**
     * Read plaintext from $sourcePath and write the final artifact to
     * $destinationPath. The destination must not exist on entry.
     *
     * Returns an EncryptResult with envelope fields populated for modes that
     * use key wrapping (app-key); returns an all-null EncryptResult for modes
     * that embed all key material in the artifact (none) or have none at all.
     */
    public function encryptFile(string $sourcePath, string $destinationPath): EncryptResult;

    /**
     * Read the artifact at $sourcePath and write plaintext to $destinationPath.
     * The destination must not exist on entry.
     *
     * Modes that store key material in the manifest (app-key) must receive a
     * non-null $manifest and will throw BackupException if it is missing.
     * Pass-through modes (none) ignore $manifest.
     */
    public function decryptFile(string $sourcePath, string $destinationPath, ?Manifest $manifest = null): void;
}
