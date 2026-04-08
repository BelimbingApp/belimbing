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
        $state = new class
        {
            public ?string $runId = null;

            public bool $thinkingPending = false;

            public string $thinkingContent = '';

            public string $fullContent = '';

            public bool $hadError = false;

            /** @var array<string, mixed> */
            public array $usageMeta = [];
        };

        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $this->applyTurnEventToMaterialization($turn, $mm, $employeeId, $sessionId, $event->event_type, $payload, $state);
        }

        $this->flushPendingThinking($mm, $employeeId, $sessionId, $state);

        if (! $state->hadError && $state->fullContent !== '' && $state->runId !== null) {
            $meta = array_merge($state->usageMeta, $extraMeta);
            $mm->appendAssistantMessage($employeeId, $sessionId, $state->fullContent, $state->runId, $meta);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  object{
     *     runId: ?string,
     *     thinkingPending: bool,
     *     thinkingContent: string,
     *     fullContent: string,
     *     hadError: bool,
     *     usageMeta: array<string, mixed>
     * }  $state
     */
    private function applyTurnEventToMaterialization(
        ChatTurn $turn,
        MessageManager $mm,
        int $employeeId,
        string $sessionId,
        TurnEventType $type,
        array $payload,
        object $state,
    ): void {
        match ($type) {
            TurnEventType::RunStarted => $this->materializeRunStarted($payload, $state),
            TurnEventType::AssistantThinkingStarted => $this->materializeThinkingStarted($mm, $employeeId, $sessionId, $state),
            TurnEventType::AssistantThinkingDelta => $this->materializeThinkingDelta($payload, $state),
            TurnEventType::ToolStarted => $this->materializeToolStarted($mm, $employeeId, $sessionId, $payload, $state),
            TurnEventType::ToolFinished => $this->materializeToolFinished($mm, $employeeId, $sessionId, $payload, $state),
            TurnEventType::ToolDenied => $this->materializeToolDenied($mm, $employeeId, $sessionId, $payload, $state),
            TurnEventType::AssistantOutputBlockCommitted => $this->materializeOutputCommitted($payload, $state),
            TurnEventType::UsageUpdated => $this->materializeUsageUpdated($payload, $state),
            TurnEventType::TurnFailed => $this->materializeTurnFailed($turn, $mm, $employeeId, $sessionId, $payload, $state),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  object{runId: ?string}  $state
     */
    private function materializeRunStarted(array $payload, object $state): void
    {
        $state->runId = $payload['run_id'] ?? $state->runId;
    }

    /**
     * @param  object{thinkingPending: bool, thinkingContent: string}  $state
     */
    private function materializeThinkingStarted(
        MessageManager $mm,
        int $employeeId,
        string $sessionId,
        object $state,
    ): void {
        $this->flushPendingThinking($mm, $employeeId, $sessionId, $state);

        $state->thinkingPending = true;
        $state->thinkingContent = '';
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  object{thinkingContent: string}  $state
     */
    private function materializeThinkingDelta(array $payload, object $state): void
    {
        $state->thinkingContent .= $payload['delta'] ?? '';
    }

    /**
     * Persist the accumulated thinking entry if pending.
     *
     * @param  object{runId: ?string, thinkingPending: bool, thinkingContent: string}  $state
     */
    private function flushPendingThinking(
        MessageManager $mm,
        int $employeeId,
        string $sessionId,
        object $state,
    ): void {
        if (! $state->thinkingPending || $state->runId === null) {
            return;
        }

        $mm->appendThinking($employeeId, $sessionId, $state->runId, $state->thinkingContent);
        $state->thinkingPending = false;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  object{runId: ?string}  $state
     */
    private function materializeToolStarted(
        MessageManager $mm,
        int $employeeId,
        string $sessionId,
        array $payload,
        object $state,
    ): void {
        if ($state->runId === null) {
            return;
        }

        $mm->appendToolCall(
            $employeeId,
            $sessionId,
            $state->runId,
            (string) ($payload['tool'] ?? ''),
            (string) ($payload['args_summary'] ?? '{}'),
            (int) ($payload['tool_call_index'] ?? 0),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  object{runId: ?string}  $state
     */
    private function materializeToolFinished(
        MessageManager $mm,
        int $employeeId,
        string $sessionId,
        array $payload,
        object $state,
    ): void {
        if ($state->runId === null) {
            return;
        }

        $mm->appendToolResult(
            $employeeId,
            $sessionId,
            $state->runId,
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

    /**
     * @param  array<string, mixed>  $payload
     * @param  object{runId: ?string}  $state
     */
    private function materializeToolDenied(
        MessageManager $mm,
        int $employeeId,
        string $sessionId,
        array $payload,
        object $state,
    ): void {
        if ($state->runId === null) {
            return;
        }

        $mm->appendHookAction(
            $employeeId,
            $sessionId,
            $state->runId,
            'pre_tool_use',
            'tool_denied',
            [
                'tool' => (string) ($payload['tool'] ?? ''),
                'reason' => (string) ($payload['reason'] ?? 'denied by policy'),
                'source' => (string) ($payload['source'] ?? 'hook'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  object{fullContent: string}  $state
     */
    private function materializeOutputCommitted(array $payload, object $state): void
    {
        $state->fullContent = (string) ($payload['content'] ?? '');
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  object{usageMeta: array<string, mixed>}  $state
     */
    private function materializeUsageUpdated(array $payload, object $state): void
    {
        $state->usageMeta['tokens'] = $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  object{runId: ?string, hadError: bool}  $state
     */
    private function materializeTurnFailed(
        ChatTurn $turn,
        MessageManager $mm,
        int $employeeId,
        string $sessionId,
        array $payload,
        object $state,
    ): void {
        $state->hadError = true;
        $errorMessage = $payload['message'] ?? __('An unexpected error occurred. Please try again.');
        $errorMeta = is_array($payload['meta'] ?? null)
            ? $payload['meta']
            : ['message_type' => 'error'];

        $mm->appendAssistantMessage(
            $employeeId,
            $sessionId,
            __('⚠ :detail', ['detail' => $errorMessage]),
            $state->runId ?? $turn->current_run_id ?? 'run_unknown',
            $errorMeta,
        );
    }
}
