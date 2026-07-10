<?php

namespace App\Base\Database\DTO\Bridge;

final readonly class BridgeExportResult
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
