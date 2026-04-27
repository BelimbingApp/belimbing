<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\Log\Livewire\Logs;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Url;
use Livewire\Component;
use SplFileObject;

class Show extends Component
{
    private const DEFAULT_CHUNK_SIZE = 100;

    private const MAX_CHUNK_SIZE = 1000;

    public string $filename = '';

    #[Url]
    public int $lines = self::DEFAULT_CHUNK_SIZE;

    #[Url]
    public string $search = '';

    #[Url]
    public string $mode = 'tail';

    #[Url]
    public int $window = 0;

    public int $deleteLines = 10;

    public function mount(string $filename): void
    {
        $this->filename = basename($filename);
        $this->ensureFileExists();
    }

    /**
     * Refresh the log view (re-renders with latest content).
     */
    public function refresh(): void
    {
        // Livewire re-renders automatically; this is an explicit action target.
    }

    /**
     * Switch between top and tail windowing modes, resetting to first window.
     */
    public function switchMode(string $mode): void
    {
        if (! in_array($mode, ['tail', 'top'], true)) {
            return;
        }

        $this->mode = $mode;
        $this->window = 0;
    }

    /**
     * Advance to the next window away from the anchor.
     *
     * In tail mode this means older lines; in top mode this means further into the file.
     */
    public function nextWindow(): void
    {
        $this->window = max(0, $this->window + 1);
    }

    /**
     * Normalize the lines-per-chunk input and reset window.
     */
    public function updatedLines(): void
    {
        $this->lines = $this->normalizedChunkSize();
        $this->window = 0;
    }

    /**
     * Delete a number of lines from the top of the log file.
     *
     * Uses streaming read/write to avoid loading the entire file into memory.
     */
    public function deleteLinesFromTop(): void
    {
        $count = $this->normalizedDeleteLines();

        $path = $this->resolvedPath();
        if ($path === null) {
            return;
        }

        $tmpPath = $path.'.tmp';
        $source = new SplFileObject($path, 'r');
        $dest = new SplFileObject($tmpPath, 'w');

        $lineIndex = 0;

        while (! $source->eof()) {
            $line = $source->fgets();

            if ($lineIndex >= $count) {
                $dest->fwrite($line);
            }

            $lineIndex++;
        }

        unset($source, $dest);
        rename($tmpPath, $path);
        $this->deleteLines = 10;
    }

    /**
     * Delete the entire log file and redirect back to the index.
     */
    public function deleteFile(): void
    {
        $path = $this->resolvedPath();
        if ($path !== null) {
            File::delete($path);
        }

        $this->redirect(route('admin.system.logs.index'), navigate: true);
    }

    public function render(): View
    {
        $logLines = [];
        $totalLines = 0;
        $fileSize = 0;
        $windowStart = 0;
        $windowEnd = 0;
        $totalWindows = 0;
        $hasMore = false;

        $path = $this->resolvedPath();

        if ($path !== null && File::exists($path)) {
            $fileSize = File::size($path);
            $totalLines = $this->countLines($path);
            $chunkSize = $this->normalizedChunkSize();
            $totalWindows = max(1, (int) ceil($totalLines / $chunkSize));
            $this->window = min(max(0, $this->window), $totalWindows - 1);

            [$windowStart, $windowEnd] = $this->resolveWindowBounds($totalLines, $chunkSize);
            $logLines = $this->readWindowLines($path, $windowStart, $windowEnd);

            $hasMore = $this->window < ($totalWindows - 1);
        }

        return view('livewire.admin.system.logs.show', [
            'logLines' => $logLines,
            'totalLines' => $totalLines,
            'fileSize' => $fileSize,
            'displayedCount' => count($logLines),
            'windowStart' => $windowStart,
            'windowEnd' => $windowEnd,
            'totalWindows' => $totalWindows,
            'hasMore' => $hasMore,
        ]);
    }

    /**
     * Resolve and validate the log file path.
     */
    private function resolvedPath(): ?string
    {
        $logPath = storage_path('logs');
        $path = $logPath.DIRECTORY_SEPARATOR.$this->filename;

        if (! File::exists($path) || ! str_starts_with(realpath($path), realpath($logPath))) {
            return null;
        }

        return $path;
    }

    /**
     * Ensure the requested file exists within the logs directory.
     */
    private function ensureFileExists(): void
    {
        if ($this->resolvedPath() === null) {
            abort(404);
        }
    }

    /**
     * Normalize requested delete lines count.
     *
     * Treat zero/negative values as the default 10 lines.
     */
    private function normalizedDeleteLines(): int
    {
        if ($this->deleteLines < 1) {
            return 10;
        }

        return $this->deleteLines;
    }

    /**
     * Normalize lines-per-chunk value.
     */
    private function normalizedChunkSize(): int
    {
        return min(self::MAX_CHUNK_SIZE, max(1, $this->lines));
    }

    /**
     * Count total lines without loading entire file into memory.
     */
    private function countLines(string $path): int
    {
        $file = new SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);

        $count = $file->key() + 1;

        if ($count === 1) {
            $file->rewind();

            if ($file->eof() || $file->current() === false || $file->current() === '') {
                return 0;
            }
        }

        return $count;
    }

    /**
     * Resolve [start, endExclusive] line indices for current mode/window.
     *
     * @return array{0:int,1:int}
     */
    private function resolveWindowBounds(int $totalLines, int $chunkSize): array
    {
        if ($totalLines === 0) {
            return [0, 0];
        }

        if ($this->mode === 'top') {
            $start = $this->window * $chunkSize;
            $end = min($totalLines, $start + $chunkSize);

            return [$start, $end];
        }

        $end = max(0, $totalLines - ($this->window * $chunkSize));
        $start = max(0, $end - $chunkSize);

        return [$start, $end];
    }

    /**
     * Read and optionally filter lines in the given window bounds.
     *
     * @return array<int, array{number:int, content:string}>
     */
    private function readWindowLines(string $path, int $start, int $end): array
    {
        if ($start >= $end) {
            return [];
        }

        $file = new SplFileObject($path, 'r');
        $file->seek($start);

        $lines = [];

        for ($index = $start; $index < $end && ! $file->eof(); $index++) {
            $line = rtrim((string) $file->current(), "\r\n");

            if ($this->search !== '' && stripos($line, $this->search) === false) {
                $file->next();

                continue;
            }

            $lines[] = [
                'number' => $index + 1,
                'content' => $line,
            ];

            $file->next();
        }

        return $lines;
    }
}
