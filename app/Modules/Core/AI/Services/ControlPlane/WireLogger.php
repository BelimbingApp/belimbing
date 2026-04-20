<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services\ControlPlane;

use App\Base\Support\File as BlbFile;
use App\Base\Support\Json as BlbJson;

class WireLogger
{
    public function enabled(): bool
    {
        return (bool) config('ai.wire_logging.enabled', false);
    }

    public function retentionDays(): int
    {
        return max(1, (int) config('ai.wire_logging.retention_days', 7));
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    public function append(string $runId, array $entry): void
    {
        if (! $this->enabled()) {
            return;
        }

        $path = $this->path($runId);
        BlbFile::ensureDirectory(dirname($path));

        BlbFile::put(
            $path,
            json_encode(array_merge([
                'at' => now()->toIso8601String(),
            ], $entry), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
            FILE_APPEND | LOCK_EX,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function read(string $runId): array
    {
        $path = $this->path($runId);

        if (! file_exists($path)) {
            return [];
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return [];
        }

        $entries = [];

        foreach ($lines as $line) {
            $decoded = BlbJson::decodeArray($line);

            if ($decoded !== null) {
                $entries[] = $decoded;
            }
        }

        return $entries;
    }

    public function path(string $runId): string
    {
        return storage_path('app/ai/wire-logs/'.$runId.'.jsonl');
    }

    public function totalBytes(): int
    {
        $wireLogPath = storage_path('app/ai/wire-logs');

        if (! is_dir($wireLogPath)) {
            return 0;
        }

        $total = 0;

        foreach (glob($wireLogPath.'/*.jsonl') ?: [] as $path) {
            $size = @filesize($path);

            if ($size !== false) {
                $total += $size;
            }
        }

        return $total;
    }

    public function pruneOlderThan(int $days): int
    {
        $cutoff = now()->subDays(max(1, $days))->getTimestamp();
        $wireLogPath = storage_path('app/ai/wire-logs');
        $deleted = 0;

        foreach (glob($wireLogPath.'/*.jsonl') ?: [] as $path) {
            $modifiedAt = @filemtime($path);

            if ($modifiedAt === false || $modifiedAt >= $cutoff) {
                continue;
            }

            if (@unlink($path)) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
