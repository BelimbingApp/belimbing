<?php

namespace App\Base\Database\DTO\Bridge;

final readonly class VerifiedBridgePackage
{
    /** @param array<string, mixed> $manifest */
    public function __construct(
        public array $manifest,
        public string $sha256,
        public int $bytes,
    ) {}
}
