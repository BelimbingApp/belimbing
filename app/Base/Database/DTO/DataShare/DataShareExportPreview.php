<?php

namespace App\Base\Database\DTO\DataShare;

final readonly class DataShareExportPreview
{
    /**
     * @param  array<string, mixed>  $report
     * @param  array<string, list<array<string, mixed>>>  $advisories  per table, one entry per column (#530)
     */
    public function __construct(
        public string $previewHash,
        public int $estimatedBytes,
        public array $report,
        public array $advisories = [],
    ) {}
}
