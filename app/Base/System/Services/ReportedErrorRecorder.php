<?php

namespace App\Base\System\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Ring buffer of recently reported exceptions, fed from the exception
 * handler's reportable pipeline — the one chokepoint every real error
 * passes through (uncaught web/console/queue exceptions and explicit
 * report() calls alike; 404s, validation and auth denials never reach
 * it because shouldReport() already filters them).
 *
 * The buffer is the signal source for the status-bar diagnostic bubble:
 * the durable record stays in the log files, this is only the "something
 * broke recently, go look" pointer. Errors are fingerprinted so a crash
 * loop counts up instead of flooding, and entries expire with the window
 * so the bubble clears itself once the problem stops recurring.
 */
final class ReportedErrorRecorder
{
    private const CACHE_KEY = 'blb.system.reported-errors';

    private const WINDOW_HOURS = 24;

    private const MAX_FINGERPRINTS = 50;

    private const MESSAGE_LIMIT = 200;

    public function record(Throwable $exception): void
    {
        // Never let error bookkeeping become an error itself: a broken
        // cache store while handling an exception must not recurse.
        try {
            $errors = $this->freshEntries();
            $fingerprint = sha1($exception::class.'|'.mb_substr($exception->getMessage(), 0, self::MESSAGE_LIMIT));

            $entry = $errors[$fingerprint] ?? [
                'exception' => $exception::class,
                'message' => mb_substr($exception->getMessage(), 0, self::MESSAGE_LIMIT),
                'count' => 0,
                'first_seen' => now()->toIso8601String(),
            ];
            $entry['count']++;
            $entry['last_seen'] = now()->toIso8601String();

            unset($errors[$fingerprint]);
            $errors[$fingerprint] = $entry; // re-append: most recent last

            if (count($errors) > self::MAX_FINGERPRINTS) {
                $errors = array_slice($errors, -self::MAX_FINGERPRINTS, preserve_keys: true);
            }

            Cache::put(self::CACHE_KEY, $errors, now()->addHours(self::WINDOW_HOURS));
        } catch (Throwable) {
            // Swallowed by design; the log file still has the original error.
        }
    }

    /**
     * Errors reported within the window, most recent last.
     *
     * @return array<string, array{exception: string, message: string, count: int, first_seen: string, last_seen: string}>
     */
    public function recent(): array
    {
        try {
            return $this->freshEntries();
        } catch (Throwable) {
            return [];
        }
    }

    public function clear(): void
    {
        try {
            Cache::forget(self::CACHE_KEY);
        } catch (Throwable) {
            // A failed clear only means the bubble lingers until expiry.
        }
    }

    /**
     * @return array<string, array{exception: string, message: string, count: int, first_seen: string, last_seen: string}>
     */
    private function freshEntries(): array
    {
        $errors = Cache::get(self::CACHE_KEY, []);

        if (! is_array($errors)) {
            return [];
        }

        $cutoff = now()->subHours(self::WINDOW_HOURS);

        return array_filter(
            $errors,
            fn (mixed $entry): bool => is_array($entry)
                && isset($entry['last_seen'])
                && now()->parse($entry['last_seen'])->isAfter($cutoff),
        );
    }
}
