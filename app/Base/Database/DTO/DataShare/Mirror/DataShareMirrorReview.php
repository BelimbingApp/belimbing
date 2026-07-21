<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

use App\Base\Database\Enums\DataShareMirrorDirection;

final readonly class DataShareMirrorReview
{
    /**
     * @param  list<DataShareMirrorReviewItem>  $items
     * @param  array{create: int, replace: int, delete: int, blocked: int}  $counts
     */
    public function __construct(
        public DataShareMirrorDirection $direction,
        public array $items,
        public bool $hasBlockers,
        public array $counts,
        public string $stateToken,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction->value,
            'items' => array_map(fn (DataShareMirrorReviewItem $item): array => $item->toArray(), $this->items),
            'has_blockers' => $this->hasBlockers,
            'counts' => $this->counts,
            'state_token' => $this->stateToken,
        ];
    }
}
