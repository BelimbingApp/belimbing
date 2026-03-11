<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Tools;

/**
 * Thrown when a tool cannot execute due to missing prerequisites.
 *
 * Covers configuration disabled, external dependency missing, service
 * unavailable, etc. Caught by AbstractTool::execute() and converted to
 * a ToolResult::unavailable() response with structured error data.
 *
 * Distinct from ToolArgumentException (bad input from LLM) — this signals
 * an infrastructure or setup problem that the user or Lara can remediate.
 */
final class ToolUnavailableException extends \RuntimeException
{
    /**
     * @param  string  $errorCode  Machine-readable error code (e.g. 'browser_disabled')
     * @param  string  $message  Human-readable error summary
     * @param  string|null  $hint  Remediation guidance
     * @param  SetupAction|null  $action  Optional Lara handoff action
     */
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly ?string $hint = null,
        public readonly ?SetupAction $action = null,
    ) {
        parent::__construct($message);
    }
}
