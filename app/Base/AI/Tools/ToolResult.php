<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Tools;

use Stringable;

/**
 * Structured result from a tool execution.
 *
 * Replaces raw string returns with typed results that distinguish success
 * from error and carry structured error payloads as first-class data.
 *
 * Three factory methods cover all tool error patterns:
 *  - success()      — normal tool output
 *  - error()        — argument validation, runtime failures, query errors
 *  - unavailable()  — tool disabled, dependency missing, config incomplete
 *
 * Implements Stringable so the LLM-facing pipeline (AgenticRuntime) can
 * cast to string for the tool message content without special handling.
 */
final readonly class ToolResult implements Stringable
{
    private const ERROR_PREFIX = 'Error: ';

    /**
     * @param  string  $content  The primary text content (LLM-facing)
     * @param  bool  $isError  Whether this result represents an error
     * @param  ToolErrorPayload|null  $errorPayload  Structured error data (null on success)
     */
    private function __construct(
        public string $content,
        public bool $isError,
        public ?ToolErrorPayload $errorPayload,
    ) {}

    /**
     * Create a successful result.
     *
     * @param  string  $content  The result content for the LLM
     */
    public static function success(string $content): self
    {
        return new self($content, false, null);
    }

    /**
     * Create an error result for argument validation or runtime failures.
     *
     * The 'Error: ' prefix is added automatically to the content so the
     * LLM recognizes the failure. Callers should not include the prefix.
     *
     * @param  string  $message  The error message (without 'Error: ' prefix)
     * @param  string  $code  Machine-readable error code (defaults to 'tool_error')
     */
    public static function error(string $message, string $code = 'tool_error'): self
    {
        return new self(
            self::ERROR_PREFIX.$message,
            true,
            new ToolErrorPayload(code: $code, message: $message),
        );
    }

    /**
     * Create a result for a tool that cannot execute due to missing prerequisites.
     *
     * The LLM receives a clear text message. The UI layer can inspect
     * $errorPayload for structured data including remediation hints and
     * optional Lara handoff actions.
     *
     * @param  string  $code  Machine-readable error code (e.g. 'browser_disabled')
     * @param  string  $message  Human-readable error summary
     * @param  string|null  $hint  Remediation guidance (shown to user, not LLM)
     * @param  SetupAction|null  $action  Optional Lara handoff action
     */
    public static function unavailable(
        string $code,
        string $message,
        ?string $hint = null,
        ?SetupAction $action = null,
    ): self {
        return new self(
            self::ERROR_PREFIX.$message,
            true,
            new ToolErrorPayload(
                code: $code,
                message: $message,
                hint: $hint,
                action: $action,
            ),
        );
    }

    /**
     * Render as string for LLM consumption.
     */
    public function __toString(): string
    {
        return $this->content;
    }
}
