<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

use App\Base\Database\Enums\DataShareMirrorAction;

final readonly class DataShareMirrorReviewItem
{
    /** @param list<DataShareMirrorBlocker> $blockers */
    public function __construct(
        public string $table,
        public DataShareMirrorAction $action,
        public DataShareMirrorAction $intendedAction,
        public array $blockers = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'table' => $this->table,
            'action' => $this->action->value,
            'intended_action' => $this->intendedAction->value,
            'blockers' => array_map(fn (DataShareMirrorBlocker $blocker): array => $blocker->toArray(), $this->blockers),
        ];
    }
}
