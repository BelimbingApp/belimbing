<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\Enums\OperationStatus;
use App\Modules\Core\AI\Enums\OperationType;
use App\Modules\Core\AI\Models\OperationDispatch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Query and lifecycle management for the operations dispatch ledger.
 *
 * Provides operator-facing queries (recent operations, stale detection,
 * filtered listings) and lifecycle actions (cancel, sweep stale).
 * Tools and commands delegate here instead of querying OperationDispatch
 * directly.
 */
class OperationsDispatchService
{
    /**
     * Default threshold in minutes for considering a running operation stale.
     */
    private const DEFAULT_STALE_MINUTES = 30;

    /**
     * Find a single operation by ID.
     */
    public function find(string $dispatchId): ?OperationDispatch
    {
        return OperationDispatch::query()->find($dispatchId);
    }

    /**
     * List recent operations, optionally filtered by type and/or status.
     *
     * @param  OperationType|null  $type  Filter by operation type
     * @param  OperationStatus|null  $status  Filter by status
     * @param  int  $limit  Maximum results
     * @return Collection<int, OperationDispatch>
     */
    public function recent(
        ?OperationType $type = null,
        ?OperationStatus $status = null,
        int $limit = 25,
    ): Collection {
        $query = OperationDispatch::query()->orderByDesc('created_at');

        if ($type !== null) {
            $query->where('operation_type', $type);
        }

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query->limit($limit)->get();
    }

    /**
     * Find operations that appear stale (running beyond threshold).
     *
     * @param  int  $staleMinutes  Threshold in minutes
     * @return Collection<int, OperationDispatch>
     */
    public function findStale(int $staleMinutes = self::DEFAULT_STALE_MINUTES): Collection
    {
        $cutoff = now()->subMinutes($staleMinutes);

        return OperationDispatch::query()
            ->where('status', OperationStatus::Running)
            ->where('started_at', '<', $cutoff)
            ->get();
    }

    /**
     * Sweep stale operations by marking them as failed.
     *
     * @param  int  $staleMinutes  Threshold in minutes
     * @return int Number of operations swept
     */
    public function sweepStale(int $staleMinutes = self::DEFAULT_STALE_MINUTES): int
    {
        $stale = $this->findStale($staleMinutes);

        foreach ($stale as $dispatch) {
            $dispatch->markFailed(
                'Operation swept as stale — running for over '
                .$staleMinutes.' minutes without completing.',
            );
        }

        return $stale->count();
    }

    /**
     * Cancel a queued operation before it starts.
     *
     * Only operations in Queued status can be cancelled. Returns false
     * if the operation is not found or not in a cancellable state.
     */
    public function cancel(string $dispatchId): bool
    {
        $dispatch = OperationDispatch::query()->find($dispatchId);

        if ($dispatch === null) {
            return false;
        }

        if ($dispatch->status !== OperationStatus::Queued) {
            return false;
        }

        $dispatch->markCancelled();

        return true;
    }

    /**
     * Get summary counts by status.
     *
     * @return array<string, int>
     */
    public function statusCounts(): array
    {
        $counts = OperationDispatch::query()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->all();

        // Ensure all statuses are represented
        foreach (OperationStatus::cases() as $status) {
            if (! isset($counts[$status->value])) {
                $counts[$status->value] = 0;
            }
        }

        return $counts;
    }

    /**
     * Count operations created within a time window.
     *
     * @param  Carbon|null  $since  Start of window (defaults to 24 hours ago)
     */
    public function countSince(?Carbon $since = null): int
    {
        $since ??= now()->subDay();

        return OperationDispatch::query()
            ->where('created_at', '>=', $since)
            ->count();
    }
}
