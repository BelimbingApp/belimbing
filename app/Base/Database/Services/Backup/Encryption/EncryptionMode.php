<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Services\Backup\Encryption;

/**
 * Backup encryption strategy.
 *
 * Each implementation knows its own on-disk format and is responsible for
 * transforming a plaintext file into a final artifact (encrypt) and back
 * (decrypt). Implementations must not leave additional plaintext copies on
 * disk beyond what the chosen mode strictly requires.
 */
interface EncryptionMode
{
    /**
     * Stable identifier persisted in the manifest (e.g. 'none', 'passphrase').
     */
    public function name(): string;

    /**
     * File extension applied to the artifact filename for this mode
     * (e.g. '' for none, '.enc' for passphrase).
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
     */
    public function encryptFile(string $sourcePath, string $destinationPath): void;

    /**
     * Read the artifact at $sourcePath and write plaintext to $destinationPath.
     * The destination must not exist on entry.
     */
    public function decryptFile(string $sourcePath, string $destinationPath): void;
}
