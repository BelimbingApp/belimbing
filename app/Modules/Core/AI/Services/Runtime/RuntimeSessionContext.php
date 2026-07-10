<?php

namespace App\Modules\Core\AI\Services\Runtime;

/**
 * Request/job-scoped holder for the active AI runtime turn.
 *
 * Tools and hooks can read the chat session id and a small bag of
 * turn-scoped values (latest user message, resolved skill packs).
 */
final class RuntimeSessionContext
{
    private ?string $sessionId = null;

    /** @var array<string, mixed> */
    private array $bag = [];

    public function set(?string $sessionId): void
    {
        $this->sessionId = is_string($sessionId) && $sessionId !== ''
            ? $sessionId
            : null;
    }

    public function clear(): void
    {
        $this->sessionId = null;
        $this->bag = [];
    }

    public function sessionId(): ?string
    {
        return $this->sessionId;
    }

    public function remember(string $key, mixed $value): void
    {
        $this->bag[$key] = $value;
    }

    public function recall(string $key, mixed $default = null): mixed
    {
        return $this->bag[$key] ?? $default;
    }
}
