<?php

namespace App\Base\Database\DTO\DataShare;

final readonly class DataShareExportPreview
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        public string $previewHash,
        public int $estimatedBytes,
        public array $report,
    ) {}
}
