<?php

namespace App\Base\Workflow\Process;

use Illuminate\Support\Carbon;

final class ProcessStartRequest
{
    /** @var array<string, mixed> */
    public array $input = [];

    public ?string $idempotencyKey = null;

    public ?string $subjectType = null;

    public int|string|null $subjectId = null;

    public ?int $definitionVersion = null;

    public ?string $correlationKey = null;

    public int $priority = 0;

    public ?Carbon $availableAt = null;

    public function __construct(public readonly string $definitionKey) {}

    /** @param array<string, mixed> $input */
    public function withInput(array $input): self
    {
        $this->input = $input;

        return $this;
    }

    public function withIdempotencyKey(?string $idempotencyKey): self
    {
        $this->idempotencyKey = $idempotencyKey;

        return $this;
    }

    public function withSubject(?string $subjectType, int|string|null $subjectId): self
    {
        $this->subjectType = $subjectType;
        $this->subjectId = $subjectId;

        return $this;
    }

    public function withDefinitionVersion(?int $definitionVersion): self
    {
        $this->definitionVersion = $definitionVersion;

        return $this;
    }

    public function withCorrelationKey(?string $correlationKey): self
    {
        $this->correlationKey = $correlationKey;

        return $this;
    }

    public function withPriority(int $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function availableAt(?Carbon $availableAt): self
    {
        $this->availableAt = $availableAt;

        return $this;
    }
}
