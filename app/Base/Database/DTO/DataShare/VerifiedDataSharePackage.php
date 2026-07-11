<?php

namespace App\Base\Database\DTO\DataShare;

final readonly class VerifiedDataSharePackage
{
    /** @param array<string, mixed> $manifest */
    public function __construct(
        public array $manifest,
        public string $sha256,
        public int $bytes,
    ) {}
}
