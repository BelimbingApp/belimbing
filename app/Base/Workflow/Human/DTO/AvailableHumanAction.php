<?php

namespace App\Base\Workflow\Human\DTO;

final readonly class AvailableHumanAction
{
    public function __construct(
        public string $key,
        public string $label,
        public bool $available = true,
        public ?string $blockedReason = null,
        public ?int $processRunId = null,
        public ?int $workItemId = null,
        public ?int $workItemVersion = null,
    ) {}
}
