<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Enums;

/**
 * Named lifecycle stages where runtime hooks may execute.
 *
 * Stages are small and deterministic. Each maps to a single
 * extension point in the agentic runtime loop. Hooks receive
 * an immutable payload and return explicit augmentations —
 * they never silently mutate runtime globals.
 */
enum HookStage: string
{
    /** Before system prompt and context assembly begins. */
    case PreContextBuild = 'pre_context_build';

    /** After tools are loaded but before the LLM sees them. */
    case PreToolRegistry = 'pre_tool_registry';

    /** Immediately before each LLM API call. */
    case PreLlmCall = 'pre_llm_call';

    /** Before a tool executes — can deny the call. */
    case PreToolUse = 'pre_tool_use';

    /** After a tool executes and before its result feeds back to the LLM. */
    case PostToolResult = 'post_tool_result';

    /** After the agentic run completes (success or failure). */
    case PostRun = 'post_run';

    /**
     * Human-readable label for UI and logging.
     */
    public function label(): string
    {
        return match ($this) {
            self::PreContextBuild => 'Pre-Context Build',
            self::PreToolRegistry => 'Pre-Tool Registry',
            self::PreLlmCall => 'Pre-LLM Call',
            self::PreToolUse => 'Pre-Tool Use',
            self::PostToolResult => 'Post-Tool Result',
            self::PostRun => 'Post-Run',
        };
    }

    /**
     * Whether the hook runs before the LLM loop commits.
     *
     * Pre-commit hooks can augment context and tools.
     * Post-commit hooks observe results but cannot change them.
     */
    public function isPreCommit(): bool
    {
        return match ($this) {
            self::PreContextBuild, self::PreToolRegistry, self::PreLlmCall, self::PreToolUse => true,
            self::PostToolResult, self::PostRun => false,
        };
    }
}
