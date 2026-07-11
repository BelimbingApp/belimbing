<?php

namespace App\Base\Database\DTO\DataShare;

final readonly class StagedDataShareUpload
{
    public function __construct(
        public string $path,
        public int $bytes,
    ) {}
}
