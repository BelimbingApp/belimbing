<?php

namespace App\Base\Perf\Services;

use Carbon\CarbonImmutable;
use Generator;
use InvalidArgumentException;

/**
 * Storage for the request performance log: one JSON line per request in a
 * daily perf-YYYY-MM-DD.jsonl file. Daily files make time-window reads cheap
 * (select files by name) and retention trivial (delete old files).
 */
final class PerfLog
{
    public function __construct(
        private readonly PerfRuntimeSettings $runtimeSettings,
    ) {}

    public function directory(): string
    {
        return $this->runtimeSettings->logPath() ?? storage_path('logs');
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function write(array $entry): void
    {
        $directory = $this->directory();

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents(
            $directory.DIRECTORY_SEPARATOR.'perf-'.now()->format('Y-m-d').'.jsonl',
            json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL,
            FILE_APPEND | LOCK_EX,
        );
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
