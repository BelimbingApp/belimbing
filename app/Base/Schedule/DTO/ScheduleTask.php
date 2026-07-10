<?php

namespace App\Base\Schedule\DTO;

use Carbon\CarbonInterface;

/**
 * A scheduled item shown on the central Schedule page, from any source.
 */
final readonly class ScheduleTask
{
    public function __construct(
        public string $source,
        public string $key,
        public string $name,
        public string $cron,
        public ?CarbonInterface $nextRunAt,
        public ?string $status = null,
        public ?CarbonInterface $lastRunAt = null,
        public ?CarbonInterface $lastFinishedAt = null,
        public ?string $lastResult = null,
        public ?string $url = null,
        public bool $paused = false,
        public bool $canRun = false,
        public bool $canPause = false,
    ) {}
}
