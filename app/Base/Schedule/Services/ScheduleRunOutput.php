<?php

namespace App\Base\Schedule\Services;

use Illuminate\Console\Scheduling\Event;

class ScheduleRunOutput
{
    private const OUTPUT_LIMIT = 2000;

    private const IGNORED_OUTPUT_PATHS = ['nul', '/dev/null'];

    public function prepare(Event $task): void
    {
        $current = $task->output ?? null;
        $default = $task->getDefaultOutput();

        if (! is_string($current) || $current === '' || $current === $default || $this->ignores($current)) {
            $task->sendOutputTo($this->deterministicPath($task));
        }
    }

    public function excerpt(Event $task, ?string $failure): ?string
    {
        return $this->merge($this->read($task), $failure);
    }

    public function merge(?string ...$parts): ?string
    {
        $merged = [];

        foreach ($parts as $part) {
            $part = $part === null ? null : trim($part);

            if ($part !== null && $part !== '' && ! in_array($part, $merged, true)) {
                $merged[] = $part;
            }
        }

        return $merged === [] ? null : $this->truncate(implode("\n", $merged));
    }

    private function deterministicPath(Event $task): string
    {
        return storage_path('logs/schedule-'.hash('sha256', $task->mutexName()).'.log');
    }

    private function read(Event $task): ?string
    {
        $path = $this->readablePath($task);

        if ($path === null) {
            return null;
        }

        $contents = $this->readFileTail($path);

        return is_string($contents) && trim($contents) !== ''
            ? $this->truncate(trim($contents))
            : null;
    }

    private function readablePath(Event $task): ?string
    {
        $path = $task->output ?? null;

        if (! is_string($path) || $path === '' || $this->ignores($path)) {
            return null;
        }

        return is_file($path) && is_readable($path) ? $path : null;
    }

    private function readFileTail(string $path): string|false
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return false;
        }

        try {
            $size = filesize($path);
            if (is_int($size) && $size > self::OUTPUT_LIMIT) {
                fseek($handle, -self::OUTPUT_LIMIT, SEEK_END);
            }

            return stream_get_contents($handle);
        } finally {
            fclose($handle);
        }
    }

    private function truncate(string $value): string
    {
        return mb_strlen($value) > self::OUTPUT_LIMIT ? mb_substr($value, 0, self::OUTPUT_LIMIT) : $value;
    }

    private function ignores(string $path): bool
    {
        return in_array(strtolower($path), self::IGNORED_OUTPUT_PATHS, true);
    }
}
