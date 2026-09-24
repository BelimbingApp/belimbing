<?php

namespace App\Base\Perf\Services;

use Carbon\CarbonImmutable;
use Generator;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Storage for the request performance log: one JSON line per request in a
 * daily perf-YYYY-MM-DD.jsonl file. Daily files make time-window reads cheap
 * (select files by name) and retention trivial (delete old files).
 *
 * Writing is best-effort: a perf record must never fail the request, job, or
 * command it measures, so write failures (full disk, wrong directory mode, a
 * log directory owned by another user) are reported once per process and
 * otherwise swallowed.
 */
final class PerfLog
{
    private bool $writeFailureReported = false;

    public function __construct(
        private readonly PerfRuntimeSettings $runtimeSettings,
    ) {}

    public function directory(): string
    {
        return $this->runtimeSettings->logPath() ?? storage_path('logs');
    }

    /**
     * Append one entry. Never throws: a failed append is reported through the
     * framework log at most once per process (see class doc) and dropped.
     *
     * @param  array<string, mixed>  $entry
     */
    public function write(array $entry): void
    {
        $path = null;

        try {
            $directory = $this->directory();
            $path = $directory.DIRECTORY_SEPARATOR.'perf-'.now()->format('Y-m-d').'.jsonl';
            $this->append($directory, $path, json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL);
        } catch (Throwable $exception) {
            $this->reportWriteFailure($path, $exception);
        }
    }

    /**
     * PHP warnings from the filesystem calls are captured by a scoped error
     * handler (so Laravel does not turn them into ErrorExceptions, and the
     * actual OS reason is kept even though error_get_last() is not updated
     * while a user handler is installed); false returns become one exception.
     */
    private function append(string $directory, string $path, string $line): void
    {
        $error = null;

        set_error_handler(function (int $errno, string $message) use (&$error): bool {
            $error = $message;

            return true;
        });

        try {
            if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
                throw new RuntimeException("Could not create perf log directory [$directory]: ".($error ?? 'unknown error'));
            }

            if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) === false) {
                throw new RuntimeException("Could not append to perf log [$path]: ".($error ?? 'unknown error'));
            }
        } finally {
            restore_error_handler();
        }
    }

    private function reportWriteFailure(?string $path, Throwable $exception): void
    {
        if ($this->writeFailureReported) {
            return;
        }

        $this->writeFailureReported = true;

        try {
            Log::warning('Perf log write failed; further failures in this process are not reported.', [
                'path' => $path,
                'error' => $exception->getMessage(),
            ]);
        } catch (Throwable) {
            // The framework log may be on the same full disk; the measured
            // work still must not fail because of instrumentation.
        }
    }

    /**
     * @return list<string> Absolute paths of all perf files, oldest first.
     */
    public function files(): array
    {
        $files = glob($this->directory().DIRECTORY_SEPARATOR.'perf-*.jsonl') ?: [];

        sort($files);

        return $files;
    }

    /**
     * Entries at or after the cutoff, in the order they were written.
     *
     * @return Generator<int, array<string, mixed>>
     */
    public function entriesSince(CarbonImmutable $cutoff): Generator
    {
        $cutoffDay = $cutoff->format('Y-m-d');
        $cutoffIso = $cutoff->toIso8601String();

        foreach ($this->files() as $file) {
            if ($this->fileDate($file) < $cutoffDay) {
                continue;
            }

            $handle = fopen($file, 'r');

            if ($handle === false) {
                continue;
            }

            try {
                while (($line = fgets($handle)) !== false) {
                    $entry = json_decode($line, true);

                    if (is_array($entry) && ($entry['ts'] ?? '') >= $cutoffIso) {
                        yield $entry;
                    }
                }
            } finally {
                fclose($handle);
            }
        }
    }

    /**
     * Parse a relative window like "30m", "2h", or "7d" into a cutoff instant.
     */
    public static function parseSince(string $since): CarbonImmutable
    {
        if (preg_match('/^(\d+)([mhd])$/', trim($since), $matches) !== 1) {
            throw new InvalidArgumentException(
                "Invalid --since value '$since'; use a number followed by m, h, or d (e.g. 30m, 2h, 7d).",
            );
        }

        $amount = (int) $matches[1];

        return match ($matches[2]) {
            'm' => CarbonImmutable::now()->subMinutes($amount),
            'h' => CarbonImmutable::now()->subHours($amount),
            'd' => CarbonImmutable::now()->subDays($amount),
        };
    }

    private function fileDate(string $path): string
    {
        return (string) preg_replace('/^perf-(\d{4}-\d{2}-\d{2})\.jsonl$/', '$1', basename($path));
    }
}
