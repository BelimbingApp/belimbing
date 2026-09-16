<?php

namespace App\Base\Workflow\Human\DTO;

final readonly class HumanActionRequest
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $actionKey,
        public string $idempotencyKey,
        public string $expectedSubjectVersion,
        public array $payload = [],
        public ?int $processRunId = null,
        public ?int $workItemId = null,
        public ?int $expectedWorkItemVersion = null,
    ) {}
}
