<?php

namespace App\Base\Schedule\DTO;

use Carbon\CarbonInterface;

/**
 * One currently active schedule task whose latest known outcome is unhealthy.
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
