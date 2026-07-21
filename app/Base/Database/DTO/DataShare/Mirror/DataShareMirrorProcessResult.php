<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

final readonly class DataShareMirrorProcessResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
    ) {}

    public function successful(): bool
    {
        return $this->exitCode === 0;
    }
}
