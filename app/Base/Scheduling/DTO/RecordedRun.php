<?php

namespace App\Base\Scheduling\DTO;

use Carbon\CarbonInterface;

/**
 * One historical execution of scheduled work, from any scheduling source.
 */
final readonly class RecordedRun
{
    public function __construct(
        public string $source,
        public string $name,
        public string $status,
        public CarbonInterface $startedAt,
        public ?CarbonInterface $finishedAt = null,
        public ?string $detail = null,
    ) {}
}
