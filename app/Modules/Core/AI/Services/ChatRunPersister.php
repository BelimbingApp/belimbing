<?php

// SPDX-License-Identifier: AGPL-3.0-only
// (c) Ng Kiat Siong <kiatsiong.ng@gmail.com>

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\ToolResultEntry;
use App\Modules\Core\AI\Enums\TurnEventType;
use App\Modules\Core\AI\Models\ChatTurn;

/**
 * Materializes transcript entries from a completed turn's event stream.
 *
 * The turn event stream (ai_chat_turn_events) is the live contract — the
 * source of truth during an active turn. This service reads those events
 * after the turn completes and writes the equivalent transcript entries
 * via MessageManager so the conversation history is available for page
 * reload, context building, and search.
 *
 * Replaces the previous inline persistence pattern where ChatRunPersister
 * consumed raw runtime events during the stream. Now all persistence
 * flows through turn events first.
 */
class ChatRunPersister
{
    /**
     * Materialize transcript entries from a turn's durable event stream.
     *
     * Reads the turn's events in seq order and writes thinking, tool call,
     * tool result, and assistant message entries to the transcript. Handles
     * both successful and failed turns.
     *
     * @param  ChatTurn  $turn  The completed (or failed) turn
     * @param  MessageManager  $mm  Message manager instance
     * @param  int  $employeeId  Agent employee ID
     * @param  string  $sessionId  Session UUID
     * @param  array<string, mixed>  $extraMeta  Extra metadata for the assistant message (e.g., prompt_package)
     */
    public function materializeFromTurn(
        ChatTurn $turn,
        MessageManager $mm,
        int $employeeId,
        string $sessionId,
        array $extraMeta = [],
    ): void {
        $events = $turn->events()->orderBy('seq')->get();
        $runId = null;
        $thinkingPersisted = false;
        $fullContent = '';
        $hadError = false;
        $usageMeta = [];

        foreach ($events as $event) {
            $type = $event->event_type;
            $payload = is_array($event->payload) ? $event->payload : [];

            if ($type === TurnEventType::RunStarted) {
                $runId = $payload['run_id'] ?? $runId;
            }

            if ($type === TurnEventType::AssistantThinkingStarted && ! $thinkingPersisted && $runId !== null) {
                $mm->appendThinking($employeeId, $sessionId, $runId);
                $thinkingPersisted = true;
            }

            if ($type === TurnEventType::ToolStarted && $runId !== null) {
                $mm->appendToolCall(
                    $employeeId,
                    $sessionId,
                    $runId,
                    (string) ($payload['tool'] ?? ''),
                    (string) ($payload['args_summary'] ?? '{}'),
                    (int) ($payload['tool_call_index'] ?? 0),
                );
            }

            if ($type === TurnEventType::ToolFinished && $runId !== null) {
                $mm->appendToolResult(
                    $employeeId,
                    $sessionId,
                    $runId,
                    new ToolResultEntry(
                        toolName: (string) ($payload['tool'] ?? ''),
                        resultPreview: (string) ($payload['result_preview'] ?? ''),
                        resultLength: (int) ($payload['result_length'] ?? 0),
                        status: (string) ($payload['status'] ?? 'success'),
                        durationMs: (int) ($payload['duration_ms'] ?? 0),
                        errorPayload: is_array($payload['error_payload'] ?? null) ? $payload['error_payload'] : null,
                    ),
                );
            }

            if ($type === TurnEventType::ToolDenied && $runId !== null) {
                $mm->appendHookAction(
                    $employeeId,
                    $sessionId,
                    $runId,
                    'pre_tool_use',
                    'tool_denied',
                    [
                        'tool' => (string) ($payload['tool'] ?? ''),
                        'reason' => (string) ($payload['reason'] ?? 'denied by policy'),
                        'source' => (string) ($payload['source'] ?? 'hook'),
                    ],
                );
            }

            if ($type === TurnEventType::AssistantOutputBlockCommitted) {
                $fullContent = (string) ($payload['content'] ?? '');
            }

            if ($type === TurnEventType::UsageUpdated) {
                $usageMeta['tokens'] = $payload;
            }

            if ($type === TurnEventType::TurnFailed) {
                $hadError = true;
                $errorMessage = $payload['message'] ?? __('An unexpected error occurred. Please try again.');
                $errorMeta = is_array($payload['meta'] ?? null)
                    ? $payload['meta']
                    : ['message_type' => 'error'];

                $mm->appendAssistantMessage(
                    $employeeId,
                    $sessionId,
                    __('⚠ :detail', ['detail' => $errorMessage]),
                    $runId ?? $turn->current_run_id ?? 'run_unknown',
                    $errorMeta,
                );
            }
        }

        if (! $hadError && $fullContent !== '' && $runId !== null) {
            $meta = array_merge($usageMeta, $extraMeta);
            $mm->appendAssistantMessage($employeeId, $sessionId, $fullContent, $runId, $meta);
        }
    }
}
