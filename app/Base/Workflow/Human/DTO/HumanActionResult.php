<?php

namespace App\Base\Workflow\Human\DTO;

final readonly class HumanActionResult
{
    /** @param array<string, mixed> $output */
    public function __construct(
        public string $actionKey,
        public array $output,
        public string $outcome,
        public ?string $resultRef,
        public ?int $workItemId,
        public bool $replayed = false,
    ) {}

    /** @param array<string, mixed> $value */
    public static function fromArray(array $value, bool $replayed = false): self
    {
        return new self(
            (string) $value['action_key'],
            (array) ($value['output'] ?? []),
            (string) ($value['outcome'] ?? 'completed'),
            isset($value['result_ref']) ? (string) $value['result_ref'] : null,
            isset($value['work_item_id']) ? (int) $value['work_item_id'] : null,
            $replayed,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['action_key' => $this->actionKey, 'output' => $this->output, 'outcome' => $this->outcome,
            'result_ref' => $this->resultRef, 'work_item_id' => $this->workItemId];
    }
}
