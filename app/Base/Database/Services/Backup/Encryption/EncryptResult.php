<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Services\Backup\Encryption;

/**
 * Result returned by EncryptionMode::encryptFile().
 *
 * Envelope encryption modes (app-key) populate wrappedDek, dekNonce, and
 * kekFingerprint with base64-encoded values that must be stored in the manifest.
 * Pass-through modes (none) leave all three null.
 */
final readonly class EncryptResult
{
    public function __construct(
        public ?string $wrappedDek = null,
        public ?string $dekNonce = null,
        public ?string $kekFingerprint = null,
    ) {}
}
