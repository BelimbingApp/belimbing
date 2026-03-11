<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Base\AI\Tools;

/**
 * Structured error data carried by a failed ToolResult.
 *
 * Provides machine-readable error classification, a human-readable message,
 * optional remediation hint, and optional setup action for Lara handoff.
 * Consumers inspect this instead of parsing error strings.
 */
final readonly class ToolErrorPayload
{
    /**
     * @param  string  $code  Machine-readable error code (e.g. 'browser_disabled', 'invalid_argument')
     * @param  string  $message  Human-readable error summary
     * @param  string|null  $hint  Remediation guidance (shown to user, not sent to LLM)
     * @param  SetupAction|null  $action  Optional Lara handoff action
     */
    public function __construct(
        public string $code,
        public string $message,
        public ?string $hint = null,
        public ?SetupAction $action = null,
    ) {}
}
