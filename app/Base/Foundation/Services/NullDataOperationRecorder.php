<?php

namespace App\Base\Foundation\Services;

use App\Base\Foundation\Contracts\DataOperationRecorder;

/**
 * Default no-op recorder so the contract is always safe to depend on even when
 * the ledger is unavailable. Base Database overrides this binding.
 */
final class NullDataOperationRecorder implements DataOperationRecorder
{
    public function open(string $operationType, array $attributes = []): int
    {
        return 0;
    }

    public function resume(int $runId): void
    {
        // Resume is intentionally ignored by the null recorder binding.
    }

    public function recordTable(int $runId, string $table, array $effect): void
    {
        // Table effects are intentionally discarded by this fallback binding.
    }

    public function finalize(int $runId, string $status, array $attributes = []): void
    {
        // Final status updates are intentionally left unrecorded here.
    }
}
