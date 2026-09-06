<?php

namespace App\Base\Integration\Services;

use App\Base\Integration\Enums\SubjectCommandExecutionState;

/**
 * Typed result from the last platform boundary before a command is dispatched.
 *
 * A refusal is proof that transport was never invoked. A connector can
 * therefore map it to not-delivered; it must never create an unknown outcome.
 */
final readonly class SubjectCommandExecution
{
    private function __construct(
        public SubjectCommandExecutionState $state,
        public mixed $value,
        public ?int $retryAfterSeconds,
    ) {}

    public static function executed(mixed $value): self
    {
        return new self(SubjectCommandExecutionState::Executed, $value, null);
    }

    public static function refusedBeforeDispatch(int $retryAfterSeconds): self
    {
        return new self(
            SubjectCommandExecutionState::RefusedBeforeDispatch,
            null,
            max(1, $retryAfterSeconds),
        );
    }

    public function wasDispatched(): bool
    {
        return $this->state === SubjectCommandExecutionState::Executed;
    }
}
