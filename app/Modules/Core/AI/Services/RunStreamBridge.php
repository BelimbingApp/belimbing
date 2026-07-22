<?php

namespace App\Modules\Core\AI\Services;

use App\Modules\Core\AI\DTO\ToolFinishedPayload;
use App\Modules\Core\AI\Enums\AiRunStatus;
use App\Modules\Core\AI\Enums\RunPhase;
use App\Modules\Core\AI\Models\AiRun;
use App\Modules\Core\AI\Models\AiRunEvent;

/**
 * Maps AgenticRuntime event streams to durable run events.
 *
 * Wraps the runtime's Generator and publishes structured run events via
 * RunEventPublisher. Yields each persisted run event payload,
 * providing a single unified event format for both live delivery
 * (via the streaming HTTP response) and replay-after-disconnect.
 *
 * The bridge:
 * 1. Transitions the run Queued → Booting → Running as events arrive
 * 2. Maps each runtime event to one or more run events
 * 3. Yields run event payloads to the streaming controller
 * 4. Finalizes the run on done/error
 *
 * Callers that abandon the generator early (e.g., cancellation) must
 * handle terminal run transitions themselves.
 *
 * Cross-reference: Claw Code ConversationRuntime in
 * `rust/crates/runtime/src/conversation.rs` — the event loop that drives
 * AssistantEvent emission during a conversation turn.
 */
class RunStreamBridge
{
    public function __construct(
        private readonly RunEventPublisher $publisher,
    ) {}

    /**
     * Wrap a runtime event stream and publish run events.
     *
     * Yields run event payloads (same format as RunEventStreamController JSON
     * response and the direct stream controller).
     *
     * @param  AiRun  $turn  A freshly created run in Queued status
     * @param  \Generator<int, array{event: string, data: array<string, mixed>}>  $runtimeStream
     * @return \Generator<int, array{run_id: string, seq: int, event_type: string, payload: mixed, occurred_at: string}>
     */
    public function wrap(AiRun $turn, \Generator $runtimeStream): \Generator
    {
        $turnStartedAt = hrtime(true);

        yield $this->publisher->turnStarted($turn)->toSsePayload();
        $turn->update(['started_at' => now()]);

        try {
            foreach ($runtimeStream as $event) {
                $data = $event['data'];

                $this->maybeTransitionToRunning($turn, $data);

                yield from match ($event['event']) {
                    'status' => $this->mapStatusEvent($turn, $data, $turnStartedAt),
                    'delta' => $this->mapDeltaEvent($turn, $data),
                    'done' => $this->mapDoneEvent($turn, $data, $turnStartedAt),
                    'error' => $this->mapErrorEvent($turn, $data),
                    default => [],
                };
            }
        } catch (\Throwable $e) {
            $turn->refresh();

            if (! $turn->isTerminal()) {
                yield $this->publisher->turnFailed($turn, 'runtime_exception', $e->getMessage())->toSsePayload();
            }

            throw $e;
        }

        // If stream completed naturally without a terminal event, fail the run.
        $turn->refresh();

        if (! $turn->isTerminal()) {
            yield $this->publisher->turnFailed(
                $turn,
                'unexpected_end',
                'Runtime stream ended without terminal event',
            )->toSsePayload();
        }
    }

    /**
     * Transition Booting → Running on first runtime event with a run_id.
     *
     * @param  array<string, mixed>  $data
     */
    private function maybeTransitionToRunning(AiRun $turn, array $data): void
    {
        if ($turn->status !== AiRunStatus::Booting) {
            return;
        }

        $runId = $data['run_id'] ?? null;

        if (! is_string($runId)) {
            return;
        }

        $turn->transitionTo(AiRunStatus::Running);
    }

    /**
     * Map a runtime 'status' event to run events.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function mapStatusEvent(AiRun $turn, array $data, int $turnStartedAt): array
    {
        return match ($data['phase'] ?? '') {
            RunPhase::AwaitingLlm->value => $this->onAwaitingLlmPhase($turn),
            RunPhase::Cancelled->value => [$this->publisher->turnCancelled($turn, 'User cancelled')->toSsePayload()],
            'thinking_delta' => $this->onThinkingDelta($turn, $data),
            'iteration_completed' => $this->onIterationCompleted($turn, $data),
            'tool_round_warning' => $this->onToolRoundWarning($turn, $data),
            'tool_started' => $this->onToolStarted($turn, $data),
            'tool_stdout' => $this->onToolStdout($turn, $data),
            'tool_finished' => $this->onToolFinished($turn, $data, $turnStartedAt),
            'tool_denied' => $this->onToolDenied($turn, $data),
            default => [],
        };
    }

    /**
     * Maps a runtime "awaiting LLM" status to phase + optional reasoning panel seed.
     *
     * @return array<int, array<string, mixed>>
     */
    private function onAwaitingLlmPhase(AiRun $turn): array
    {
        $label = RunPhase::AwaitingLlm->label();

        return [
            $this->publisher->phaseChanged($turn, RunPhase::AwaitingLlm, $label)->toSsePayload(),
            $this->publisher->thinkingStarted($turn)->toSsePayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function onThinkingDelta(AiRun $turn, array $data): array
    {
        return [
            $this->publisher->thinkingDelta($turn, (string) ($data['delta'] ?? ''))->toSsePayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function onIterationCompleted(AiRun $turn, array $data): array
    {
        $finishReason = (string) ($data['finish_reason'] ?? '');

        if ($finishReason === '') {
            return [];
        }

        return [
            $this->publisher->iterationCompleted(
                $turn,
                $finishReason,
                isset($data['iteration']) ? (int) $data['iteration'] : null,
                isset($data['tool_call_count']) ? (int) $data['tool_call_count'] : null,
                isset($data['tool_round']) ? (int) $data['tool_round'] : null,
                isset($data['max_tool_rounds']) ? (int) $data['max_tool_rounds'] : null,
            )->toSsePayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function onToolRoundWarning(AiRun $turn, array $data): array
    {
        return [
            $this->publisher->toolRoundWarning(
                $turn,
                (int) ($data['tool_round_count'] ?? 0),
                (int) ($data['max_tool_rounds'] ?? 0),
            )->toSsePayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function onToolStarted(AiRun $turn, array $data): array
    {
        $toolName = (string) ($data['tool'] ?? 'tool');

        return [
            $this->publisher->phaseChanged($turn, RunPhase::RunningTool, $toolName)->toSsePayload(),
            $this->publisher->toolStarted(
                $turn,
                $toolName,
                $data['args_summary'] ?? null,
                isset($data['tool_call_index']) ? (int) $data['tool_call_index'] : null,
                is_string($data['display_summary'] ?? null) ? $data['display_summary'] : null,
            )->toSsePayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function onToolStdout(AiRun $turn, array $data): array
    {
        return [
            $this->publisher->toolStdoutDelta(
                $turn,
                (string) ($data['tool'] ?? ''),
                (string) ($data['delta'] ?? ''),
            )->toSsePayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function onToolFinished(AiRun $turn, array $data, int $turnStartedAt): array
    {
        $elapsedMs = (int) ((hrtime(true) - $turnStartedAt) / 1_000_000);
        $toolName = (string) ($data['tool'] ?? '');
        $postToolLabel = RunPhase::AwaitingLlm->label();

        return [
            $this->publisher->toolFinished(
                $turn,
                $toolName,
                ToolFinishedPayload::fromStreamData($data),
            )->toSsePayload(),
            $this->publisher->phaseChanged($turn, RunPhase::AwaitingLlm, $postToolLabel)->toSsePayload(),
            $this->publisher->heartbeat($turn, $elapsedMs)->toSsePayload(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function onToolDenied(AiRun $turn, array $data): array
    {
        return [
            $this->publisher->toolDenied(
                $turn,
                (string) ($data['tool'] ?? ''),
                (string) ($data['reason'] ?? 'denied'),
                (string) ($data['source'] ?? 'hook'),
            )->toSsePayload(),
        ];
    }

    /**
     * Map a runtime 'delta' event to an output delta run event.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function mapDeltaEvent(AiRun $turn, array $data): array
    {
        $events = [];

        if ($turn->current_phase !== RunPhase::StreamingAnswer) {
            $events[] = $this->publisher->phaseChanged($turn, RunPhase::StreamingAnswer, 'Responding…')->toSsePayload();
        }

        $events[] = $this->publisher->outputDelta($turn, $data['text'] ?? '')->toSsePayload();

        return $events;
    }

    /**
     * Map a runtime 'done' event to run completion events.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function mapDoneEvent(AiRun $turn, array $data, int $turnStartedAt): array
    {
        $elapsedMs = (int) ((hrtime(true) - $turnStartedAt) / 1_000_000);
        $events = [];

        $events[] = $this->publisher->phaseChanged($turn, RunPhase::Finalizing, 'Finishing up…')->toSsePayload();

        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        if (isset($meta['tokens'])) {
            $events[] = $this->publisher->usageUpdated($turn, $meta['tokens'])->toSsePayload();
        }

        $content = $data['content'] ?? '';

        if ($content !== '') {
            $events[] = $this->publisher->outputBlockCommitted($turn, 'markdown', $content)->toSsePayload();
        }

        $events[] = $this->publisher->turnCompleted($turn, [
            'run_id' => $data['run_id'] ?? null,
            'elapsed_ms' => $elapsedMs,
            'tool_round_count' => $meta['tool_round_count'] ?? 0,
            'tool_call_count' => $meta['tool_call_count'] ?? 0,
            'max_tool_rounds' => $meta['max_tool_rounds'] ?? null,
        ])->toSsePayload();

        // turnCompleted also publishes ReadyForInput for chat — read it back.
        $readyEvent = AiRunEvent::query()
            ->where('run_id', $turn->id)
            ->where('event_type', 'run.ready_for_input')
            ->orderByDesc('seq')
            ->first();

        if ($readyEvent !== null) {
            $events[] = $readyEvent->toSsePayload();
        }

        return $events;
    }

    /**
     * Map a runtime 'error' event to a run failure.
     *
     * @param  array<string, mixed>  $data
     * @return array<int, array<string, mixed>>
     */
    private function mapErrorEvent(AiRun $turn, array $data): array
    {
        $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];

        return [
            $this->publisher->turnFailed(
                $turn,
                $meta['error_type'] ?? 'unknown',
                $data['message'] ?? 'An unexpected error occurred',
                $meta !== [] ? $meta : null,
            )->toSsePayload(),
        ];
    }
}
