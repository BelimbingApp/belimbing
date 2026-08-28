<?php

namespace App\Base\Schedule\DTO;

use Carbon\CarbonInterface;

/**
 * One currently-active scheduled task whose latest definitive outcome is a
 * failure, for the status-bar health projection.
 */
final readonly class UnhealthyScheduleTask
{
    public function __construct(
        public string $source,
        public string $key,
        public string $name,
        public CarbonInterface $lastAttemptAt,
        public int $consecutiveFailures,
    ) {}
}
