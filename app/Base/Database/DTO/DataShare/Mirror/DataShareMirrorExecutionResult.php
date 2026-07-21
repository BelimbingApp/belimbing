<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

use App\Base\Database\Enums\DataShareMirrorDirection;

final readonly class DataShareMirrorExecutionResult
{
    /**
     * @param  array{create: int, replace: int, delete: int}  $counts
     * @param  list<array{table: string, action: string}>  $items
     */
    public function __construct(
        public DataShareMirrorDirection $direction,
        public array $counts,
        public array $items,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction->value,
            'counts' => $this->counts,
            'items' => $this->items,
        ];
    }
}
