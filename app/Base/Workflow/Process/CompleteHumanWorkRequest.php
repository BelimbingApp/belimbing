<?php

namespace App\Base\Workflow\Process;

final class CompleteHumanWorkRequest
{
    /** @var array<string, mixed> */
    public array $output = [];

    public string $outcome = 'completed';

    public ?string $resultRef = null;

    /** @var array<string, mixed> */
    public array $eventContext = [];

    public function __construct(
        public readonly int $tenantId,
        public readonly int $runId,
        public readonly int $workItemId,
        public readonly int $expectedVersion,
        public readonly string $executorKey,
    ) {}

    /** @param array<string, mixed> $output */
    public function withResult(array $output, string $outcome = 'completed', ?string $resultRef = null): self
    {
        $this->output = $output;
        $this->outcome = $outcome;
        $this->resultRef = $resultRef;

        return $this;
    }

    /** @param array<string, mixed> $eventContext */
    public function withEventContext(array $eventContext): self
    {
        $this->eventContext = $eventContext;

        return $this;
    }
}
