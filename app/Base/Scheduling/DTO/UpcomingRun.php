<?php

namespace App\Base\Scheduling\DTO;

use Carbon\CarbonInterface;

/**
 * A scheduled item's next planned execution, from any scheduling source.
 */
final readonly class UpcomingRun
{
    public function __construct(
        public string $source,
        public string $name,
        public string $cron,
        public ?CarbonInterface $nextRunAt,
        public ?string $lastStatus = null,
        public ?string $url = null,
    ) {}
}
