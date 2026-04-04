<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\ToolResultEntry;
use Illuminate\Support\Str;

/**
 * Stateless service that persists chat run activity (status events,
 * assistant messages, errors) to the transcript via MessageManager.
 *
 * Extracted from ChatStreamController so both the SSE controller
 * and background jobs can reuse the same persistence logic.
 */
class ChatRunPersister
{
    /**
     * Persist a status-event phase (thinking, tool_started, tool_finished,
     * hook_action, tool_denied) to the transcript.
     *
     * @param  MessageManager  $mm  Message manager instance
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session UUID
     * @param  ?string  $runId  Current run ID (no-op when null)
     * @param  array<string, mixed>  $data  SSE status event payload
     * @param  bool  &$thinkingPersisted  Guard flag — set to true after first thinking entry
     */
    public function persistStatusEvent(
        MessageManager $mm,
        int $employeeId,
        string $sessionId,
        ?string $runId,
        array $data,
        bool &$thinkingPersisted,
    ): void {
        if ($runId === null) {
            return;
        }

        $phase = $data['phase'] ?? '';

        if ($phase === 'thinking' && ! $thinkingPersisted) {
            $mm->appendThinking($employeeId, $sessionId, $runId);
            $thinkingPersisted = true;

            return;
        }

        if ($phase === 'tool_started') {
            $mm->appendToolCall(
                $employeeId,
                $sessionId,
                $runId,
                (string) ($data['tool'] ?? ''),
                (string) ($data['args_summary'] ?? '{}'),
                (int) ($data['tool_call_index'] ?? 0),
            );

            return;
        }

        if ($phase === 'tool_finished') {
            $mm->appendToolResult(
                $employeeId,
                $sessionId,
                $runId,
                new ToolResultEntry(
                    toolName: (string) ($data['tool'] ?? ''),
                    resultPreview: (string) ($data['result_preview'] ?? ''),
                    resultLength: (int) ($data['result_length'] ?? 0),
                    status: (string) ($data['status'] ?? 'success'),
                    durationMs: (int) ($data['duration_ms'] ?? 0),
                    errorPayload: is_array($data['error_payload'] ?? null) ? $data['error_payload'] : null,
                ),
            );

            return;
        }

        if ($phase === 'hook_action') {
            $mm->appendHookAction(
                $employeeId,
                $sessionId,
                $runId,
                (string) ($data['stage'] ?? 'unknown'),
                'tools_removed',
                array_filter([
                    'tools_removed' => $data['tools_removed'] ?? [],
                ]),
            );

            return;
        }

        if ($phase === 'tool_denied') {
            $mm->appendHookAction(
                $employeeId,
                $sessionId,
                $runId,
                'pre_tool_use',
                'tool_denied',
                [
                    'tool' => (string) ($data['tool'] ?? ''),
                    'reason' => (string) ($data['reason'] ?? 'denied by policy'),
                    'source' => (string) ($data['source'] ?? 'hook'),
                ],
            );
        }
    }

    /**
     * Append the assistant message to the session transcript.
     *
     * No-op when fullContent or runId is null (stream ended without a 'done' event).
     *
     * @param  MessageManager  $mm  Message manager instance
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session UUID
     * @param  ?string  $fullContent  Accumulated assistant response text
     * @param  ?string  $runId  Run identifier
     * @param  array<string, mixed>  $meta  Extra metadata to store
     */
    public function persistAssistantMessage(
        MessageManager $mm,
        int $employeeId,
        string $sessionId,
        ?string $fullContent,
        ?string $runId,
        array $meta,
    ): void {
        if ($fullContent !== null && $runId !== null) {
            $mm->appendAssistantMessage($employeeId, $sessionId, $fullContent, $runId, $meta);
        }
    }

    /**
     * Persist a structured error as an assistant message with error metadata.
     *
     * @param  MessageManager  $mm  Message manager instance
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session UUID
     * @param  array<string, mixed>  $data  Error event payload (message, meta, run_id)
     */
    public function persistError(
        MessageManager $mm,
        int $employeeId,
        string $sessionId,
        array $data,
    ): void {
        $errorMeta = is_array($data['meta'] ?? null)
            ? $data['meta']
            : ['message_type' => 'error'];

        $errorMessage = $data['message'] ?? __('An unexpected error occurred. Please try again.');
        $runId = $data['run_id'] ?? 'run_'.Str::random(12);

        $mm->appendAssistantMessage(
            $employeeId,
            $sessionId,
            __('⚠ :detail', ['detail' => $errorMessage]),
            $runId,
            $errorMeta,
        );
    }
}
