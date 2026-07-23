<?php

namespace App\Base\Perf\DTO;

final readonly class PerfRuntimeSettingsSnapshot
{
    public function __construct(
        public bool $enabled,
        public float $minimumDurationMs,
        public float $slowSqlMinimumDurationMs,
        public ?string $logPath,
        public int $retentionDays,
    ) {}
}
