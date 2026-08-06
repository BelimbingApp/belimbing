<?php

namespace App\Base\Queue\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Rolling count of recent queue job failures.
 *
 * Owns the whole rule — cache key, window, threshold, and the zero floor —
 * so the writer (queue events) and the reader ({@see QueueStatusDiagnosticProvider})
 * cannot drift apart on what "recent failures" means.
 *
 * Successful jobs drain the counter so a recovered queue clears its own
 * warning. The drain is floored at zero deliberately: an unbounded decrement
 * lets a healthy queue push the count arbitrarily negative, which silently
 * disables the alarm for every failure that follows.
 *
 * Every operation is best-effort. A health counter must never turn a cache
 * outage into a failed queue worker, so cache errors degrade to "no count".
 */
final class QueueFailureRateMonitor
{
    public const HIGH_FAILURE_RATE_THRESHOLD = 10;

    private const CACHE_KEY = 'queue_failures';

    private const WINDOW_MINUTES = 60;

    /**
     * Record a failure and return the count for the current window.
     */
    public function record(): int
    {
        try {
            $failures = max(1, (int) Cache::increment(self::CACHE_KEY));

            // Re-apply the TTL on every failure so the window rolls forward
            // from the latest failure instead of expiring mid-incident.
            Cache::put(self::CACHE_KEY, $failures, now()->addMinutes(self::WINDOW_MINUTES));

            return $failures;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Credit a successful job against the failure count, never below zero.
     */
    public function drain(): void
    {
        try {
            if ($this->recentFailures() > 0) {
                Cache::decrement(self::CACHE_KEY);
            }
        } catch (\Throwable) {
            // Best-effort only; a stale count is better than a failed worker.
        }
    }

    /**
     * Failures recorded in the current window.
     */
    public function recentFailures(): int
    {
        try {
            return max(0, (int) Cache::get(self::CACHE_KEY, 0));
        } catch (\Throwable) {
            return 0;
        }
    }

    public function exceedsThreshold(int $failures): bool
    {
        return $failures > self::HIGH_FAILURE_RATE_THRESHOLD;
    }
}
