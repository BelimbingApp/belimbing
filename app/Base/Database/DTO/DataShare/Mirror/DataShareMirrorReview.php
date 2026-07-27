<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

use App\Base\Database\Enums\DataShareMirrorDirection;

final readonly class DataShareMirrorReview
{
    /**
     * @param  list<DataShareMirrorReviewItem>  $items
     * @param  array{create: int, replace: int, delete: int, blocked: int}  $counts
     * @param  list<string>  $requestedTables
     * @param  list<string>  $requiredTables
     * @param  array<string, list<string>>  $requiredBy
     */
    public function __construct(
        public DataShareMirrorDirection $direction,
        public array $items,
        public bool $hasBlockers,
        public array $counts,
        public string $stateToken,
        public array $requestedTables = [],
        public array $requiredTables = [],
        public array $requiredBy = [],
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
            'selected_tables' => array_map(fn (DataShareMirrorReviewItem $item): string => $item->table, $this->items),
            'requested_tables' => $this->requestedTables,
            'required_tables' => $this->requiredTables,
            'required_by' => $this->requiredBy,
        ];
    }
}
