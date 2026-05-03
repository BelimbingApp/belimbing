<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Database\Services\Backup;

/**
 * A decrypted plaintext dump staged on the local filesystem for restore.
 *
 * Caller owns the lifecycle of $plainPath and must unlink it after use.
 */
final readonly class StagedDump
{
    public function __construct(
        public Manifest $manifest,
        public string $plainPath,
    ) {}
}
