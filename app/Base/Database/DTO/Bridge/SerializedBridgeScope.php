<?php

namespace App\Base\Database\DTO\Bridge;

final readonly class SerializedBridgeScope
{
    /**
     * @param  list<SerializedTablePayload>  $payloads
     * @param  array{tables: int, records: int}  $counts
     */
    public function __construct(
        public array $payloads,
        public array $counts,
    ) {}

    public function cleanup(): void
    {
        foreach ($this->payloads as $payload) {
            @unlink($payload->path);
        }
    }
}
