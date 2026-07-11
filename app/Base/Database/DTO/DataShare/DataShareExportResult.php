<?php

namespace App\Base\Database\DTO\DataShare;

final readonly class DataShareExportResult
{
    /** @param array<string, mixed> $manifest */
    public function __construct(
        public string $packageId,
        public string $path,
        public string $sha256,
        public int $bytes,
        public array $manifest,
    ) {}
}
