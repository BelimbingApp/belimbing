<?php

namespace App\Base\Database\DTO\DataShare;

final readonly class SerializedTablePayload
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $path,
        public array $metadata,
    ) {}
}
