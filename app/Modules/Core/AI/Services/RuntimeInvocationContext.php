<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

/**
 * Invocation context for a single LLM runtime call.
 *
 * Carries the stable operator-facing source label and optional task key for
 * runs that originate from a named Lara task profile. Pass this to
 * {@see AgenticRuntime::run()} / {@see AgenticRuntime::runStream()} so the
 * run ledger records a truthful source instead of a hardcoded 'chat'/'stream'.
 */
final readonly class RuntimeInvocationContext
{
    public function __construct(
        /** Stable, operator-visible label written to the run ledger {@see AiRun::$source}. */
        public string $source,
        /** Task key when the call originates from a Lara task profile (e.g. 'titling'). */
        public ?string $taskKey = null,
    ) {}

    /**
     * Standard Lara chat turn (non-streaming).
     */
    public static function forChat(): self
    {
        return new self(source: 'chat');
    }

    /**
     * Standard Lara chat turn (streaming).
     */
    public static function forStream(): self
    {
        return new self(source: 'stream');
    }

    /**
     * Simple Lara task — single inference, no tools, short output budget.
     */
    public static function forSimpleTask(string $taskKey): self
    {
        return new self(source: 'simple_task', taskKey: $taskKey);
    }

    /**
     * Agentic Lara task — sub-agent delegation with own prompt, tools, and loop.
     */
    public static function forAgenticTask(string $taskKey): self
    {
        return new self(source: 'lara_task', taskKey: $taskKey);
    }
}
