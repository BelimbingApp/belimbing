<?php

namespace App\Base\Schedule\DTO;

use App\Base\Schedule\Models\ScheduleRun;
use App\Base\Schedule\Services\ScheduleRunRecorder;
use App\Base\Schedule\Support\ScheduleRunStatuses;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Carbon;

class ScheduledTaskRow
{
    /**
     * @param  array<int, string>  $flags
     */
    public function __construct(
        public readonly string $command,
        public readonly string $commandKey,
        public readonly string $expression,
        public readonly ?string $description,
        public readonly array $flags,
        public readonly string $lastStatus,
        public readonly ?int $runId,
        public readonly ?Carbon $lastFinishedAt,
        public readonly ?Carbon $lastStartedAt,
        public readonly ?Carbon $nextRunAt,
        public readonly ?int $lastRuntimeMs,
        public readonly ?int $lastExitCode,
        public readonly ?string $lastOutputPreview,
    ) {}

    public static function fromEvent(Event $event, ?ScheduleRun $run, ScheduleRunRecorder $recorder): self
    {
        $command = $recorder->normalizeCommand($event->command);

        return new self(
            command: $command,
            commandKey: $command,
            expression: (string) $event->expression,
            description: is_string($event->description) && trim($event->description) !== ''
                ? trim($event->description)
                : null,
            flags: array_values(array_filter([
                $event->withoutOverlapping ? 'withoutOverlapping' : null,
                $event->onOneServer ? 'onOneServer' : null,
                $event->runInBackground ? 'runInBackground' : null,
            ])),
            lastStatus: $run?->status ?? ScheduleRunStatuses::NEVER,
            runId: $run?->id,
            lastFinishedAt: $run?->finished_at,
            lastStartedAt: $run?->started_at,
            nextRunAt: self::nextRunAt($event),
            lastRuntimeMs: $run?->runtime_ms,
            lastExitCode: $run?->exit_code,
            lastOutputPreview: self::preview($run?->output),
        );
    }

    public function lastRunAt(): ?Carbon
    {
        return $this->lastFinishedAt ?? $this->lastStartedAt;
    }

    public function statusLabel(): string
    {
        return ScheduleRunStatuses::label($this->lastStatus);
    }

    public function statusVariant(): string
    {
        return ScheduleRunStatuses::variant($this->lastStatus);
    }

    public function isRunning(): bool
    {
        return $this->lastStatus === ScheduleRunStatuses::RUNNING;
    }

    public function runtimeLabel(): ?string
    {
        if ($this->lastRuntimeMs === null) {
            return null;
        }

        if ($this->lastRuntimeMs < 1000) {
            return __(':ms ms', ['ms' => $this->lastRuntimeMs]);
        }

        return __(':seconds s', ['seconds' => number_format($this->lastRuntimeMs / 1000, 2)]);
    }

    public function runRefLabel(): ?string
    {
        return $this->runId === null ? null : '#'.$this->runId;
    }

    private static function nextRunAt(Event $event): ?Carbon
    {
        try {
            return Carbon::instance($event->nextRunDate())->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function preview(?string $output): ?string
    {
        if ($output === null || trim($output) === '') {
            return null;
        }

        $output = trim($output);

        return mb_strlen($output) > 240 ? mb_substr($output, 0, 240).'...' : $output;
    }
}
