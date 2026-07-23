<?php

namespace App\Base\Database\DTO\DataShare\Mirror;

use App\Base\Database\Enums\DataShareMirrorDirection;

final readonly class DataShareMirrorExecutionResult
{
    /**
     * @param  array{create: int, replace: int, delete: int}  $counts
     * @param  list<array{table: string, action: string, local_rows?: int, remote_rows?: int}>  $items
     */
    public function __construct(
        public DataShareMirrorDirection $direction,
        public array $counts,
        public array $items,
        // The durable data operation ledger run this result was recorded under,
        // so the UI can link to /admin/system/data-operations instead of relying
        // on a transient result table.
        public ?int $runId = null,
    ) {}

    public function withRunId(?int $runId): self
    {
        return new self($this->direction, $this->counts, $this->items, $runId);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'direction' => $this->direction->value,
            'counts' => $this->counts,
            'items' => $this->items,
            'run_id' => $this->runId,
        ];
    }
}
