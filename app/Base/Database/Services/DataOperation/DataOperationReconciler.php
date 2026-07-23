<?php

namespace App\Base\Database\Services\DataOperation;

use App\Base\Database\Enums\DataOperationStatus;
use App\Base\Database\Models\DataOperationRun;
use App\Base\Foundation\Contracts\DataOperationRecorder;
use Illuminate\Support\Facades\Schema;

/**
 * Reconciles stale running operations. A run that stays `running` past the
 * configured operation timeout plus a bounded grace period is reconciled to
 * `indeterminate` — never guessed to have failed, because an external transfer
 * may already have committed at the destination.
 *
 * Reconciliation goes through {@see DataOperationRecorder::finalize()}, so it
 * uses the same atomic terminal claim and best-effort audit projection as a
 * normal completion and can never race a late in-flight finalize.
 */
final class DataOperationReconciler
{
    public const DEFAULT_STALE_AFTER_MINUTES = 120;

    public function __construct(
        private readonly DataOperationRecorder $recorder,
    ) {}

    /**
     * @return int the number of runs reconciled to indeterminate
     */
    public function reconcileStale(?int $staleAfterMinutes = null): int
    {
        if (! Schema::hasTable('base_database_data_operation_runs')) {
            return 0;
        }

        $minutes = $staleAfterMinutes
            ?? (int) config('data_share.operation.stale_after_minutes', self::DEFAULT_STALE_AFTER_MINUTES);
        $threshold = now()->subMinutes(max(1, $minutes));

        $staleIds = DataOperationRun::query()
            ->where('status', DataOperationStatus::Running->value)
            ->where('started_at', '<', $threshold)
            ->pluck('id');

        foreach ($staleIds as $id) {
            $this->recorder->finalize((int) $id, DataOperationStatus::Indeterminate->value, [
                'failure_summary' => 'Reconciled: the operation exceeded its timeout while running; its outcome is unknown.',
            ]);
        }

        return $staleIds->count();
    }
}
