<?php

namespace App\Base\Database\DTO\Bridge;

final readonly class BridgeExportPreview
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
