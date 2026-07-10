<?php

namespace App\Base\Schedule\DTO;

use Carbon\CarbonInterface;

/**
 * One historical execution of scheduled work, from any schedule source.
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
