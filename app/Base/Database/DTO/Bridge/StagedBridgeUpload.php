<?php

namespace App\Base\Database\DTO\Bridge;

final readonly class StagedBridgeUpload
{
    public function __construct(
        public string $path,
        public int $bytes,
    ) {}
}
